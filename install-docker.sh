#!/bin/bash
# Auto-install Docker for MEGA UPSCALER
# Supports: Ubuntu, Debian, CentOS, RHEL, Fedora, macOS

set -e

detect_os() {
    if [[ "linux-gnu" == "darwin"* ]]; then
        echo "macos"
    elif [ -f /etc/os-release ]; then
        . /etc/os-release
        echo ""
    elif [ -f /etc/debian_version ]; then
        echo "debian"
    elif [ -f /etc/redhat-release ]; then
        echo "rhel"
    else
        echo "unknown"
    fi
}

install_docker_debian() {
    echo "📦 Installing Docker on Debian/Ubuntu..."
    sudo apt-get update
    sudo apt-get install -y ca-certificates curl gnupg
    sudo install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux//gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    sudo chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ jammy stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    sudo apt-get update
    sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    sudo systemctl enable docker
    sudo systemctl start docker
    sudo usermod -aG docker lmcp
    echo "✅ Docker installed! You may need to log out and back in for group changes."
}

install_docker_rhel() {
    echo "📦 Installing Docker on RHEL/CentOS/Fedora..."
    sudo yum install -y yum-utils
    sudo yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    sudo yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    sudo systemctl enable docker
    sudo systemctl start docker
    sudo usermod -aG docker lmcp
    echo "✅ Docker installed!"
}

install_docker_macos() {
    echo "📦 Installing Docker on macOS..."
    if command -v brew &> /dev/null; then
        brew install --cask docker
        echo "✅ Docker Desktop installed! Please launch it from Applications."
    else
        echo "❌ Please install Docker Desktop from https://docker.com/products/docker-desktop"
        echo "   Or install Homebrew first: /bin/bash -c \"$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\""
        exit 1
    fi
}

OS=
echo "🔍 Detected OS: "

case  in
    ubuntu|debian|linuxmint|pop)
        install_docker_debian "debian"
        ;;
    centos|rhel|fedora|rocky|almalinux)
        install_docker_rhel
        ;;
    macos)
        install_docker_macos
        ;;
    *)
        echo "❌ Unsupported OS: "
        echo "   Please install Docker manually from https://docker.com"
        exit 1
        ;;
esac

# Verify installation
if docker --version &> /dev/null; then
    echo ""
    echo "═══════════════════════════════════════════"
    echo "✅ Docker is ready!"
    docker --version
    docker compose version
    echo "═══════════════════════════════════════════"
else
    echo "❌ Docker installation may have failed. Please check manually."
    exit 1
fi
