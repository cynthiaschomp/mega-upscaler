# 🖼️ MEGA UPSCALER v3.2

**AI-Powered Image Upscaling for Large Format Printing**

Transform any image into massive, print-ready files up to **6+ gigapixels** using Real-ESRGAN neural network technology.

---

## ✨ Features

- **AI Upscaling** - Real-ESRGAN neural network for superior quality
- **Huge Output** - Up to 140,400 × 43,200 px (6.06 gigapixels)  
- **Memory Safe** - Chunked processing handles any image size
- **GPU Accelerated** - NVIDIA CUDA support for faster processing
- **Web Interface** - Drag-and-drop UI with real-time progress
- **Job Persistence** - Redis-backed job queue survives restarts
- **TIFF Output** - Professional print-ready files with LZW compression

---

## 📋 Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **RAM** | 8 GB | 16+ GB |
| **Docker** | 20.0+ | Latest |
| **Docker Compose** | v2.0+ | Latest |
| **GPU** | Optional | NVIDIA with 4GB+ VRAM |
| **Storage** | 20 GB | 100+ GB |

---

## 🚀 Quick Install

### Option 1: Web Installer (Recommended)

1. Upload the package to your server
2. Navigate to `http://your-server/mega-upscaler/install.php`
3. Follow the wizard

### Option 2: Manual Install

```bash
# Extract package
unzip mega-upscaler-v3.2.zip
cd mega-upscaler-v3.2

# Build and run
docker compose build
docker compose up -d

# Access at http://localhost:15073
```

---

## ⚙️ Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_HOST` | `mega-upscaler-redis` | Redis server hostname |
| `REDIS_PORT` | `6379` | Redis server port |

### Target Dimensions

Default output: **39ft × 12ft @ 300 PPI** (140,400 × 43,200 pixels)

These can be adjusted in the web interface for each job.

---

## 📁 Directory Structure

```
mega-upscaler/
├── app/
│   └── main.py           # Main application
├── web/
│   └── index.html        # Web interface
├── uploads/              # Input images
├── outputs/              # Processed TIFF files
├── thumbnails/           # Job previews
├── weights/              # AI model weights (auto-downloaded)
├── redis-data/           # Redis persistence
├── docker-compose.yml    # Docker configuration
├── Dockerfile            # Build instructions
├── requirements.txt      # Python dependencies
└── install.php           # Web installer
```

---

## 🔧 Troubleshooting

### Container won't start
```bash
docker compose logs mega-upscaler
```

### Out of memory
- Reduce tile size in configuration
- Ensure swap space is enabled
- Process smaller images first

### GPU not detected
```bash
# Check NVIDIA driver
nvidia-smi

# Check Docker GPU access
docker run --rm --gpus all nvidia/cuda:11.0-base nvidia-smi
```

---

## 🗑️ Uninstall

```bash
cd mega-upscaler
docker compose down -v
cd ..
rm -rf mega-upscaler
```

---

## 📄 License

MIT License - Free for personal and commercial use.

---

## 🙏 Credits

- **Real-ESRGAN** - AI upscaling model by Xintao Wang
- **FastAPI** - Web framework
- **Pillow** - Image processing

---

Made with 💜 by Schomp Technologies
