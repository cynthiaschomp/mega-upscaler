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
- **No API Keys** - Fully self-hosted, no cloud dependencies
- **One-Click Setup** - Web wizard with auto-install for Docker

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

### Web Installer (Recommended - Zero Technical Knowledge Required)

1. Download/upload the package to your web server
2. Navigate to `http://your-server/mega-upscaler/install.php`
3. Follow the 6-step wizard:

| Step | Name | What Happens |
|------|------|--------------|
| 1 | Welcome | Introduction |
| 2 | System Check | Auto-detects Docker, RAM, GPU. **Click "Auto-Install Docker" if missing!** |
| 3 | Redis Setup | Choose bundled (recommended), external, or fresh install |
| 4 | Configuration | Set port and install directory |
| 5 | Install | One-click Docker build |
| 6 | Complete | 🎉 Launch your app! |

**Don't have Docker?** The wizard detects this and shows a green **"🔧 Auto-Install Docker"** button. One click installs Docker automatically!

### Manual Install (for developers)

```bash
# Clone the repository
git clone https://github.com/cynthiaschomp/mega-upscaler.git
cd mega-upscaler

# Build and run
docker compose build
docker compose up -d

# Access at http://localhost:15073
```

---

## 🐳 Docker Installation

### Option 1: One-Click in Web Wizard (Easiest)

The install wizard automatically detects if Docker is missing and offers a **one-click install button**. Just click it and wait!

### Option 2: Command Line

```bash
chmod +x install-docker.sh
sudo ./install-docker.sh
```

**Supported operating systems:**

| OS Family | Distributions |
|-----------|---------------|
| Debian-based | Ubuntu, Debian, Linux Mint, Pop!_OS |
| RHEL-based | CentOS, RHEL, Fedora, Rocky Linux, AlmaLinux |
| macOS | Via Homebrew (Docker Desktop) |

**What the installer does:**
1. Detects your operating system
2. Adds Docker's official repository
3. Installs Docker Engine and Docker Compose
4. Starts the Docker service
5. Adds your user to the docker group

> **Note:** After installation, log out and back in for group changes to take effect.

---

## 🗄️ Redis Configuration

Redis persists job state so your queue survives container restarts.

### Option A: Bundled Redis (Default - Recommended)

The installer creates a dedicated Redis container automatically. Zero configuration needed.

```yaml
# Included in docker-compose.yml
services:
  mega-upscaler-redis:
    image: redis:alpine
    volumes:
      - ./redis-data:/data
```

### Option B: Use External Redis

If you already have Redis running, set these environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_HOST` | `mega-upscaler-redis` | Redis hostname or IP |
| `REDIS_PORT` | `6379` | Redis port |

**Example:**
```bash
docker run -d \
  -e REDIS_HOST=192.168.1.100 \
  -e REDIS_PORT=6379 \
  -p 15073:15073 \
  mega-upscaler
```

### Option C: Install Redis on Host

The web installer wizard can install Redis directly on your host system if you prefer not to use Docker for Redis.

### Redis Data Persistence

Job data is stored in `./redis-data/` and persists across restarts. To clear all jobs:

```bash
docker compose exec mega-upscaler-redis redis-cli FLUSHALL
```

---

## ⚙️ Configuration

### Target Dimensions

Default output: **39ft × 12ft @ 300 PPI** (140,400 × 43,200 pixels)

Adjustable per-job in the web interface:
- Width/Height (feet or pixels)
- PPI (72-600)
- Scale factor (2x, 4x, 8x, 16x)

### GPU Acceleration

GPU is auto-detected if you have:
1. NVIDIA GPU with 4GB+ VRAM
2. NVIDIA drivers installed
3. NVIDIA Container Toolkit

**Verify GPU access:**
```bash
docker run --rm --gpus all nvidia/cuda:11.0-base nvidia-smi
```

CPU-only mode works but is significantly slower.

---

## 📁 Directory Structure

```
mega-upscaler/
├── app/
│   └── main.py           # FastAPI backend
├── web/
│   └── index.html        # Web interface
├── uploads/              # Input images (auto-created)
├── outputs/              # Processed TIFF files (auto-created)
├── thumbnails/           # Job previews (auto-created)
├── weights/              # AI model weights (auto-downloaded)
├── redis-data/           # Redis persistence (auto-created)
├── docker-compose.yml    # Docker configuration
├── Dockerfile            # Build instructions
├── requirements.txt      # Python dependencies
├── install.php           # Web installer wizard
└── install-docker.sh     # Docker auto-installer
```

---

## 🔧 Troubleshooting

### Container won't start
```bash
docker compose logs mega-upscaler
```

### Out of memory
```bash
# Add swap space
sudo fallocate -l 8G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
```

### GPU not detected
```bash
# Install NVIDIA Container Toolkit (Ubuntu/Debian)
curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | \
  sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
  sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
sudo apt-get update && sudo apt-get install -y nvidia-container-toolkit
sudo systemctl restart docker
```

### Redis connection failed
```bash
# Check Redis container
docker ps | grep redis
docker compose logs mega-upscaler-redis

# Test connection
docker exec mega-upscaler-redis redis-cli ping
# Should return: PONG
```

---

## 🗑️ Uninstall

```bash
cd mega-upscaler
docker compose down -v    # Stop and remove volumes
cd ..
rm -rf mega-upscaler      # Remove files
```

---

## 📄 License

MIT License - Free for personal and commercial use.

---

## 🙏 Credits

- **[Real-ESRGAN](https://github.com/xinntao/Real-ESRGAN)** - AI upscaling by Xintao Wang
- **[FastAPI](https://fastapi.tiangolo.com/)** - Python web framework
- **[Pillow](https://pillow.readthedocs.io/)** - Image processing
- **[Redis](https://redis.io/)** - Job persistence

---

Made with 💜 by [Schomp Technologies](https://schomp.ai)
