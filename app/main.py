#!/usr/bin/env python3
"""
MEGA UPSCALER v3.2 - Memory-Safe AI Image Upscaling
====================================================
v3.2 Changes:
- HARD CAP on intermediate image size (max 500MP per pass)
- Stop AI upscaling when ANY dimension exceeds target
- Direct LANCZOS resize for final step (memory efficient)
- Better progress updates during long operations
"""

import os
import sys
import math
import tempfile
import shutil
import gc
from pathlib import Path
from typing import Optional, Tuple, Dict, Any
from dataclasses import dataclass, asdict
from datetime import datetime, timedelta
import logging
import uuid
import json
import time
import threading
import traceback
import io

import numpy as np
from PIL import Image, ImageFile
Image.MAX_IMAGE_PIXELS = None
ImageFile.LOAD_TRUNCATED_IMAGES = True

from fastapi import FastAPI, UploadFile, File, Form, HTTPException, BackgroundTasks
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
import uvicorn
import redis

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s | %(levelname)s | %(message)s',
    datefmt='%H:%M:%S'
)
logger = logging.getLogger(__name__)

# =============================================================================
# HARD LIMITS
# =============================================================================
MAX_INTERMEDIATE_PIXELS = 500_000_000  # 500 megapixels max per pass (~1.5GB RGB)
MAX_RAM_IMAGE_PIXELS = 200_000_000     # 200MP max to hold in RAM at once

# =============================================================================
# Redis Configuration
# =============================================================================
REDIS_HOST = os.getenv('REDIS_HOST', 'localhost')
REDIS_PORT = int(os.getenv('REDIS_PORT', 6379))
JOB_TTL_SECONDS = 60 * 60 * 24 * 7

try:
    redis_client = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)
    redis_client.ping()
    REDIS_AVAILABLE = True
    logger.info(f"✅ Redis connected at {REDIS_HOST}:{REDIS_PORT}")
except Exception as e:
    redis_client = None
    REDIS_AVAILABLE = False
    logger.warning(f"⚠️ Redis not available: {e}")

memory_jobs: Dict[str, dict] = {}


@dataclass
class UpscaleConfig:
    target_width_ft: float = 39.0
    target_height_ft: float = 12.0
    target_ppi: int = 300
    tile_size: int = 256
    tile_overlap: int = 16
    model_name: str = "RealESRGAN_x4plus"
    gpu_id: int = 0
    fp16: bool = True
    
    @property
    def target_width_px(self) -> int:
        return int(self.target_width_ft * 12 * self.target_ppi)
    
    @property
    def target_height_px(self) -> int:
        return int(self.target_height_ft * 12 * self.target_ppi)
    
    @property
    def target_pixels(self) -> int:
        return self.target_width_px * self.target_height_px


@dataclass
class JobState:
    id: str
    filename: str
    status: str = "queued"
    progress: float = 0.0
    stage: str = "Waiting"
    stage_detail: str = ""
    error: str = ""
    created_at: str = ""
    started_at: str = ""
    completed_at: str = ""
    eta_seconds: int = 0
    elapsed_seconds: int = 0
    input_width: int = 0
    input_height: int = 0
    input_pixels: int = 0
    target_width: int = 0
    target_height: int = 0
    output_size: int = 0
    output_path: str = ""
    thumbnail_path: str = ""
    scale_factor: float = 0.0
    num_passes: int = 0
    current_pass: int = 0
    tiles_total: int = 0
    tiles_done: int = 0
    
    def to_dict(self) -> dict:
        return asdict(self)
    
    @classmethod
    def from_dict(cls, data: dict) -> 'JobState':
        fields = {k: v for k, v in data.items() if k in cls.__dataclass_fields__}
        return cls(**fields)


# =============================================================================
# Job Storage
# =============================================================================
def save_job(job: JobState):
    data = json.dumps(job.to_dict())
    if REDIS_AVAILABLE:
        redis_client.setex(f"upscaler:job:{job.id}", JOB_TTL_SECONDS, data)
        redis_client.zadd("upscaler:jobs", {job.id: time.time()})
    else:
        memory_jobs[job.id] = job.to_dict()


def load_job(job_id: str) -> Optional[JobState]:
    if REDIS_AVAILABLE:
        data = redis_client.get(f"upscaler:job:{job_id}")
        if data:
            return JobState.from_dict(json.loads(data))
    else:
        if job_id in memory_jobs:
            return JobState.from_dict(memory_jobs[job_id])
    return None


def list_jobs(limit: int = 50) -> list:
    if REDIS_AVAILABLE:
        job_ids = redis_client.zrevrange("upscaler:jobs", 0, limit - 1)
        jobs = []
        for jid in job_ids:
            job = load_job(jid)
            if job:
                jobs.append(job.to_dict())
        return jobs
    else:
        return sorted(memory_jobs.values(), key=lambda x: x.get('created_at', ''), reverse=True)[:limit]


def delete_job(job_id: str) -> bool:
    job = load_job(job_id)
    if job:
        if job.output_path and os.path.exists(job.output_path):
            try: os.remove(job.output_path)
            except: pass
        if job.thumbnail_path and os.path.exists(job.thumbnail_path):
            try: os.remove(job.thumbnail_path)
            except: pass
        if REDIS_AVAILABLE:
            redis_client.delete(f"upscaler:job:{job_id}")
            redis_client.zrem("upscaler:jobs", job_id)
        else:
            memory_jobs.pop(job_id, None)
        return True
    return False


# =============================================================================
# Progress Tracker
# =============================================================================
class ProgressTracker:
    INIT_END = 5
    TILES_END = 80
    RESIZE_END = 92
    SAVE_END = 97
    THUMB_END = 99
    
    def __init__(self, job: JobState):
        self.job = job
        self.start_time = time.time()
        self.tile_times: list = []
    
    def _update_and_save(self):
        self.job.elapsed_seconds = int(time.time() - self.start_time)
        save_job(self.job)
    
    def set_stage(self, progress: float, stage: str, detail: str = "", eta: int = None):
        self.job.progress = min(progress, 100)
        self.job.stage = stage
        self.job.stage_detail = detail
        if eta is not None:
            self.job.eta_seconds = eta
        self._update_and_save()
    
    def set_tile_progress(self, pass_num: int, total_passes: int, 
                          tiles_done: int, tiles_total: int, detail: str = ""):
        pass_range = (self.TILES_END - self.INIT_END) / max(total_passes, 1)
        pass_base = self.INIT_END + (pass_num - 1) * pass_range
        tile_progress = (tiles_done / max(tiles_total, 1)) * pass_range
        
        self.job.progress = min(pass_base + tile_progress, self.TILES_END)
        self.job.stage = f"AI Upscaling Pass {pass_num}/{total_passes}"
        self.job.stage_detail = detail or f"Tile {tiles_done}/{tiles_total}"
        self.job.current_pass = pass_num
        self.job.tiles_done = tiles_done
        self.job.tiles_total = tiles_total
        
        if self.tile_times:
            avg_tile = sum(self.tile_times) / len(self.tile_times)
            remaining = tiles_total - tiles_done
            self.job.eta_seconds = int(remaining * avg_tile * 1.3)
        
        self._update_and_save()
    
    def log_tile_time(self, duration: float):
        self.tile_times.append(duration)
        if len(self.tile_times) > 50:
            self.tile_times.pop(0)
    
    def set_complete(self, output_size: int):
        self.job.progress = 100
        self.job.stage = "Complete"
        self.job.stage_detail = f"Output: {output_size / 1e9:.2f} GB"
        self.job.status = "complete"
        self.job.completed_at = datetime.now().isoformat()
        self.job.eta_seconds = 0
        self.job.output_size = output_size
        self._update_and_save()
    
    def set_failed(self, error: str):
        self.job.status = "failed"
        self.job.stage = "Failed"
        self.job.stage_detail = error[:200]
        self.job.error = error
        self.job.completed_at = datetime.now().isoformat()
        self._update_and_save()


def generate_thumbnail(input_path: str, output_path: str, max_size: int = 200):
    try:
        with Image.open(input_path) as img:
            img.thumbnail((max_size, max_size), Image.Resampling.LANCZOS)
            img.save(output_path, "JPEG", quality=85)
        return True
    except Exception as e:
        logger.error(f"Thumbnail failed: {e}")
        return False


# =============================================================================
# MEMORY-SAFE UPSCALER
# =============================================================================
class MemorySafeUpscaler:
    
    def __init__(self, config: Optional[UpscaleConfig] = None):
        self.config = config or UpscaleConfig()
        self.model = None
        self._model_loaded = False
    
    def _load_model(self):
        if self._model_loaded:
            return
        
        try:
            from realesrgan import RealESRGANer
            from basicsr.archs.rrdbnet_arch import RRDBNet
            import torch
            
            logger.info(f"Loading {self.config.model_name}...")
            
            model = RRDBNet(
                num_in_ch=3, num_out_ch=3, num_feat=64, 
                num_block=23, num_grow_ch=32, scale=4
            )
            
            model_path = Path("/app/weights/RealESRGAN_x4plus.pth")
            if not model_path.exists():
                model_path = None
            
            self.model = RealESRGANer(
                scale=4,
                model_path=str(model_path) if model_path else None,
                dni_weight=None,
                model=model,
                tile=self.config.tile_size,
                tile_pad=self.config.tile_overlap,
                pre_pad=0,
                half=self.config.fp16 and torch.cuda.is_available(),
                gpu_id=self.config.gpu_id if torch.cuda.is_available() else None
            )
            self._model_loaded = True
            logger.info("✅ Model loaded")
        except ImportError as e:
            logger.warning(f"⚠️ Real-ESRGAN not available: {e}. Using bicubic.")
            self.model = None
            self._model_loaded = True
    
    def _calculate_safe_passes(self, input_w: int, input_h: int, 
                                target_w: int, target_h: int) -> int:
        """
        Calculate passes with HARD LIMITS:
        1. Stop if next pass would exceed MAX_INTERMEDIATE_PIXELS
        2. Stop if EITHER dimension already exceeds target
        """
        w, h = input_w, input_h
        passes = 0
        
        while True:
            # Check if we've reached target (either dimension)
            if w >= target_w or h >= target_h:
                logger.info(f"  Stopping: dimension reached target ({w}x{h})")
                break
            
            # Check if next pass would exceed pixel limit
            next_w, next_h = w * 4, h * 4
            next_pixels = next_w * next_h
            
            if next_pixels > MAX_INTERMEDIATE_PIXELS:
                logger.info(f"  Stopping: next pass would be {next_pixels/1e6:.0f}MP (limit: {MAX_INTERMEDIATE_PIXELS/1e6:.0f}MP)")
                break
            
            # Safe to do another pass
            w, h = next_w, next_h
            passes += 1
            logger.info(f"  Pass {passes} would produce: {w}x{h} ({w*h/1e6:.0f}MP)")
        
        return passes
    
    def _upscale_tile(self, tile: np.ndarray) -> np.ndarray:
        if self.model is None:
            img = Image.fromarray(tile)
            w, h = img.size
            result = img.resize((w * 4, h * 4), Image.BICUBIC)
            del img
            return np.array(result)
        
        output, _ = self.model.enhance(tile, outscale=4)
        return output
    
    def _process_pass_tiled(self, input_path: str, output_path: str, 
                            progress: ProgressTracker, 
                            pass_num: int, total_passes: int) -> Tuple[int, int]:
        """Process one 4x upscale pass with tiled approach"""
        
        progress.set_stage(
            progress.INIT_END + (pass_num - 1) * (progress.TILES_END - progress.INIT_END) / total_passes,
            f"Pass {pass_num}/{total_passes}: Loading",
            "Opening input image..."
        )
        
        input_img = Image.open(input_path)
        input_img = input_img.convert('RGB')
        in_w, in_h = input_img.size
        out_w, out_h = in_w * 4, in_h * 4
        
        logger.info(f"Pass {pass_num}: {in_w}x{in_h} → {out_w}x{out_h} ({out_w*out_h/1e6:.0f}MP)")
        
        # Verify output size is safe
        if out_w * out_h > MAX_INTERMEDIATE_PIXELS:
            raise ValueError(f"Output would be {out_w*out_h/1e6:.0f}MP, exceeds limit")
        
        tile_size = self.config.tile_size
        overlap = self.config.tile_overlap
        step = tile_size - overlap
        
        tiles_x = max(1, math.ceil((in_w - overlap) / step)) if in_w > tile_size else 1
        tiles_y = max(1, math.ceil((in_h - overlap) / step)) if in_h > tile_size else 1
        total_tiles = tiles_x * tiles_y
        
        logger.info(f"  Tiles: {tiles_x}x{tiles_y} = {total_tiles}")
        
        # Create output image
        output_img = Image.new('RGB', (out_w, out_h))
        
        tile_count = 0
        for ty in range(tiles_y):
            for tx in range(tiles_x):
                tile_start = time.time()
                
                # Calculate input tile coords
                x1 = min(tx * step, max(0, in_w - tile_size))
                y1 = min(ty * step, max(0, in_h - tile_size))
                x2 = min(x1 + tile_size, in_w)
                y2 = min(y1 + tile_size, in_h)
                
                # Extract and upscale
                tile = input_img.crop((x1, y1, x2, y2))
                tile_arr = np.array(tile)
                del tile
                
                upscaled_arr = self._upscale_tile(tile_arr)
                del tile_arr
                
                upscaled_tile = Image.fromarray(upscaled_arr)
                del upscaled_arr
                
                # Paste to output
                out_x1 = x1 * 4
                out_y1 = y1 * 4
                output_img.paste(upscaled_tile, (out_x1, out_y1))
                del upscaled_tile
                
                gc.collect()
                
                tile_count += 1
                tile_time = time.time() - tile_start
                progress.log_tile_time(tile_time)
                
                progress.set_tile_progress(
                    pass_num, total_passes,
                    tile_count, total_tiles,
                    f"Tile {tile_count}/{total_tiles} ({tile_time:.1f}s)"
                )
                
                if tile_count % 50 == 0:
                    logger.info(f"  Progress: {tile_count}/{total_tiles} tiles")
        
        input_img.close()
        del input_img
        gc.collect()
        
        # Save intermediate
        progress.set_stage(
            progress.INIT_END + pass_num * (progress.TILES_END - progress.INIT_END) / total_passes,
            f"Pass {pass_num}/{total_passes}: Saving",
            f"Writing {out_w}x{out_h} intermediate..."
        )
        
        logger.info(f"  Saving intermediate: {output_path}")
        output_img.save(output_path, format='PNG', compress_level=1)
        output_img.close()
        del output_img
        gc.collect()
        
        logger.info(f"Pass {pass_num} complete")
        return out_w, out_h
    
    def _final_resize_chunked(self, input_path: str, output_path: str,
                               target_w: int, target_h: int, 
                               progress: ProgressTracker):
        """Memory-efficient final resize using chunked processing"""
        
        progress.set_stage(progress.TILES_END, "Final Resize", "Loading upscaled image...")
        
        input_img = Image.open(input_path)
        in_w, in_h = input_img.size
        in_pixels = in_w * in_h
        
        logger.info(f"Final resize: {in_w}x{in_h} → {target_w}x{target_h}")
        
        # For smaller images, direct resize
        if in_pixels < MAX_RAM_IMAGE_PIXELS:
            progress.set_stage(progress.TILES_END + 3, "Final Resize", 
                             f"Direct resize {in_w}x{in_h} → {target_w}x{target_h}")
            
            result = input_img.resize((target_w, target_h), Image.LANCZOS)
            input_img.close()
            
            progress.set_stage(progress.RESIZE_END, "Saving TIFF", 
                             "Compressing with LZW (this takes time)...")
            
            result.save(output_path, format='TIFF', compression='lzw')
            result.close()
            gc.collect()
            return
        
        # Chunked resize for large images
        progress.set_stage(progress.TILES_END + 2, "Final Resize", 
                          f"Chunked resize (input too large for RAM)")
        
        chunk_height = 1000  # Process 1000 input rows at a time
        output_img = Image.new('RGB', (target_w, target_h))
        
        scale_y = target_h / in_h
        
        processed = 0
        y = 0
        while y < in_h:
            h = min(chunk_height, in_h - y)
            
            # Extract strip
            strip = input_img.crop((0, y, in_w, y + h))
            
            # Calculate output position
            out_y = int(y * scale_y)
            out_h = int((y + h) * scale_y) - out_y
            
            # Resize strip to target width and calculated height
            resized = strip.resize((target_w, out_h), Image.LANCZOS)
            strip.close()
            
            # Paste
            output_img.paste(resized, (0, out_y))
            resized.close()
            
            processed += h
            pct = progress.TILES_END + (progress.RESIZE_END - progress.TILES_END) * (processed / in_h)
            progress.set_stage(pct, "Final Resize", 
                             f"Processing row {processed}/{in_h}")
            
            y += h
            gc.collect()
        
        input_img.close()
        gc.collect()
        
        # Save
        progress.set_stage(progress.RESIZE_END, "Saving TIFF", 
                          f"Writing {target_w}x{target_h} with LZW compression...")
        
        logger.info(f"Saving final TIFF: {output_path}")
        output_img.save(output_path, format='TIFF', compression='lzw')
        
        progress.set_stage(progress.SAVE_END, "Saving TIFF", "Finalizing...")
        output_img.close()
        gc.collect()
    
    def process_job(self, job: JobState, input_path: str, output_dir: str, thumbnail_dir: str):
        temp_files = []
        progress = ProgressTracker(job)
        
        try:
            job.status = "processing"
            job.started_at = datetime.now().isoformat()
            save_job(job)
            
            progress.set_stage(1, "Initializing", "Loading AI model...")
            self._load_model()
            
            progress.set_stage(3, "Analyzing", "Reading image dimensions...")
            
            with Image.open(input_path) as img:
                src_w, src_h = img.size
            
            job.input_width = src_w
            job.input_height = src_h
            job.input_pixels = src_w * src_h
            job.target_width = self.config.target_width_px
            job.target_height = self.config.target_height_px
            
            # Calculate SAFE number of passes
            logger.info(f"Source: {src_w}x{src_h} ({src_w*src_h/1e6:.0f}MP)")
            logger.info(f"Target: {self.config.target_width_px}x{self.config.target_height_px}")
            logger.info(f"Calculating safe passes (max {MAX_INTERMEDIATE_PIXELS/1e6:.0f}MP per pass)...")
            
            num_passes = self._calculate_safe_passes(
                src_w, src_h,
                self.config.target_width_px,
                self.config.target_height_px
            )
            
            job.num_passes = num_passes
            job.scale_factor = round(max(
                self.config.target_width_px / src_w,
                self.config.target_height_px / src_h
            ), 1)
            save_job(job)
            
            logger.info(f"Will do {num_passes} AI pass(es), then final resize")
            
            current_input = input_path
            current_w, current_h = src_w, src_h
            
            # AI upscale passes
            for pass_num in range(1, num_passes + 1):
                job.current_pass = pass_num
                
                temp_fd, temp_path = tempfile.mkstemp(suffix='.png')
                os.close(temp_fd)
                temp_files.append(temp_path)
                
                current_w, current_h = self._process_pass_tiled(
                    current_input, temp_path,
                    progress, pass_num, num_passes
                )
                
                # Cleanup previous temp
                if current_input != input_path and os.path.exists(current_input):
                    os.remove(current_input)
                    temp_files = [t for t in temp_files if t != current_input]
                
                current_input = temp_path
                gc.collect()
            
            # Final resize to exact target
            output_path = os.path.join(output_dir, f"{job.id}.tiff")
            self._final_resize_chunked(
                current_input, output_path,
                self.config.target_width_px,
                self.config.target_height_px,
                progress
            )
            
            job.output_path = output_path
            job.output_size = os.path.getsize(output_path)
            
            # Thumbnail
            progress.set_stage(progress.THUMB_END, "Thumbnail", "Generating preview...")
            thumb_path = os.path.join(thumbnail_dir, f"{job.id}_thumb.jpg")
            if generate_thumbnail(job.output_path, thumb_path):
                job.thumbnail_path = thumb_path
            
            # Complete!
            progress.set_complete(job.output_size)
            logger.info(f"✅ Job {job.id} complete: {job.output_path}")
            
        except Exception as e:
            logger.error(f"❌ Job {job.id} failed: {e}")
            logger.error(traceback.format_exc())
            progress.set_failed(str(e))
        
        finally:
            for tf in temp_files:
                if os.path.exists(tf):
                    try:
                        os.remove(tf)
                    except:
                        pass
            gc.collect()


# =============================================================================
# FastAPI Application
# =============================================================================
app = FastAPI(title="MEGA UPSCALER v3.2", description="Memory-Safe AI Upscaling")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

BASE_DIR = Path("/app")
UPLOAD_DIR = BASE_DIR / "uploads"
OUTPUT_DIR = BASE_DIR / "outputs"
THUMB_DIR = BASE_DIR / "thumbnails"
WEB_DIR = BASE_DIR / "web"

for d in [UPLOAD_DIR, OUTPUT_DIR, THUMB_DIR]:
    d.mkdir(parents=True, exist_ok=True)

app.mount("/static", StaticFiles(directory=str(WEB_DIR)), name="static")

upscaler = MemorySafeUpscaler()


@app.get("/")
async def root():
    return FileResponse(str(WEB_DIR / "index.html"))


@app.get("/api/config")
async def get_config():
    cfg = upscaler.config
    return {
        "target_width_ft": cfg.target_width_ft,
        "target_height_ft": cfg.target_height_ft,
        "target_ppi": cfg.target_ppi,
        "target_width_px": cfg.target_width_px,
        "target_height_px": cfg.target_height_px,
        "target_pixels": cfg.target_pixels,
        "model": cfg.model_name,
        "tile_size": cfg.tile_size,
        "max_intermediate_mp": MAX_INTERMEDIATE_PIXELS / 1e6,
        "redis_connected": REDIS_AVAILABLE,
        "version": "3.2-memory-safe"
    }


@app.post("/api/analyze")
async def analyze_image(
    file: UploadFile = File(...),
    width_ft: float = Form(39.0),
    height_ft: float = Form(12.0),
    ppi: int = Form(300)
):
    try:
        contents = await file.read()
        img = Image.open(io.BytesIO(contents))
        w, h = img.size
        img.close()
        
        target_w = int(width_ft * 12 * ppi)
        target_h = int(height_ft * 12 * ppi)
        
        # Calculate safe passes
        test_w, test_h = w, h
        num_passes = 0
        while True:
            if test_w >= target_w or test_h >= target_h:
                break
            next_w, next_h = test_w * 4, test_h * 4
            if next_w * next_h > MAX_INTERMEDIATE_PIXELS:
                break
            test_w, test_h = next_w, next_h
            num_passes += 1
        
        return {
            "input": {
                "width": w,
                "height": h,
                "pixels": w * h,
            },
            "target": {
                "width_ft": width_ft,
                "height_ft": height_ft,
                "ppi": ppi,
                "width_px": target_w,
                "height_px": target_h,
                "pixels": target_w * target_h
            },
            "processing": {
                "scale_factor": round(max(target_w / w, target_h / h), 2),
                "num_passes": num_passes,
                "max_intermediate_mp": MAX_INTERMEDIATE_PIXELS / 1e6,
                "estimated_size_gb": round(target_w * target_h * 3 / 1e9, 2),
            }
        }
    except Exception as e:
        raise HTTPException(status_code=400, detail=str(e))


@app.post("/api/upscale")
async def start_upscale(
    background_tasks: BackgroundTasks,
    file: UploadFile = File(...),
    width_ft: float = Form(39.0),
    height_ft: float = Form(12.0),
    ppi: int = Form(300)
):
    job_id = str(uuid.uuid4())[:8]
    
    upload_path = UPLOAD_DIR / f"{job_id}_{file.filename}"
    contents = await file.read()
    with open(upload_path, "wb") as f:
        f.write(contents)
    
    job = JobState(
        id=job_id,
        filename=file.filename,
        created_at=datetime.now().isoformat()
    )
    save_job(job)
    
    upscaler.config.target_width_ft = width_ft
    upscaler.config.target_height_ft = height_ft
    upscaler.config.target_ppi = ppi
    
    background_tasks.add_task(
        upscaler.process_job, 
        job, 
        str(upload_path), 
        str(OUTPUT_DIR),
        str(THUMB_DIR)
    )
    
    return {"job_id": job_id, "status": "queued"}


@app.get("/api/status/{job_id}")
async def get_status(job_id: str):
    job = load_job(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    return job.to_dict()


@app.get("/api/jobs")
async def get_jobs(limit: int = 50):
    return list_jobs(limit)


@app.get("/api/download/{job_id}")
async def download_result(job_id: str):
    job = load_job(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.status != "complete":
        raise HTTPException(status_code=400, detail="Job not complete")
    if not os.path.exists(job.output_path):
        raise HTTPException(status_code=404, detail="Output file not found")
    
    return FileResponse(
        job.output_path,
        media_type="image/tiff",
        filename=f"upscaled_{job.filename}.tiff"
    )


@app.get("/api/thumbnail/{job_id}")
async def get_thumbnail(job_id: str):
    job = load_job(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if not job.thumbnail_path or not os.path.exists(job.thumbnail_path):
        raise HTTPException(status_code=404, detail="Thumbnail not found")
    return FileResponse(job.thumbnail_path, media_type="image/jpeg")


@app.delete("/api/job/{job_id}")
async def remove_job(job_id: str):
    if delete_job(job_id):
        return {"status": "deleted"}
    raise HTTPException(status_code=404, detail="Job not found")


@app.post("/api/retry/{job_id}")
async def retry_job(job_id: str, background_tasks: BackgroundTasks):
    job = load_job(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.status != "failed":
        raise HTTPException(status_code=400, detail="Job not failed")
    
    upload_path = None
    for f in UPLOAD_DIR.iterdir():
        if f.name.startswith(job_id):
            upload_path = f
            break
    
    if not upload_path or not upload_path.exists():
        raise HTTPException(status_code=404, detail="Original upload not found")
    
    job.status = "queued"
    job.progress = 0
    job.stage = "Waiting (retry)"
    job.stage_detail = ""
    job.error = ""
    job.started_at = ""
    job.completed_at = ""
    job.elapsed_seconds = 0
    save_job(job)
    
    background_tasks.add_task(
        upscaler.process_job,
        job,
        str(upload_path),
        str(OUTPUT_DIR),
        str(THUMB_DIR)
    )
    
    return {"status": "retrying", "job_id": job_id}


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
