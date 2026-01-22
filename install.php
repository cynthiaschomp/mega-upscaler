<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  MEGA UPSCALER v3.2 - Installation Wizard
 * ═══════════════════════════════════════════════════════════════════════════
 *  AI-Powered Image Upscaling for Large Format Printing
 *  
 *  This installer will:
 *  - Check system requirements
 *  - Extract application files
 *  - Configure Redis (bundled, external, or install fresh)
 *  - Set up Docker containers
 *  - Launch the application
 * ═══════════════════════════════════════════════════════════════════════════
 */

session_start();

// Handle Docker auto-installation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_docker'])) {
    $result = installDocker();
    $_SESSION['docker_install_result'] = $result;
    // Clear cached checks so they re-run
    unset($_SESSION['checks']);
    header('Location: ?step=2&docker_attempted=1');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

// ═══════════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════
define('APP_NAME', 'MEGA UPSCALER');
define('APP_VERSION', '3.2');
define('MIN_PHP', '7.4.0');
define('MIN_RAM_GB', 8);
define('REC_RAM_GB', 16);

$STEPS = [
    1 => ['name' => 'Welcome', 'icon' => '👋'],
    2 => ['name' => 'System Check', 'icon' => '🔍'],
    3 => ['name' => 'Redis Setup', 'icon' => '🗄️'],
    4 => ['name' => 'Configuration', 'icon' => '⚙️'],
    5 => ['name' => 'Install', 'icon' => '📦'],
    6 => ['name' => 'Complete', 'icon' => '🎉']
];

$step = max(1, min(6, (int)($_GET['step'] ?? 1)));
$errors = [];
$messages = [];

// ═══════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

function execCmd($cmd) {
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return ['output' => implode("\n", $output), 'code' => $code, 'lines' => $output];
}

function canExec() {
    return function_exists('exec') && 
           !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
}

function checkDocker() {
    if (!canExec()) return ['installed' => false, 'version' => null];
    $r = execCmd('docker --version');
    $installed = $r['code'] === 0 && strpos($r['output'], 'Docker') !== false;
    preg_match('/Docker version ([0-9.]+)/', $r['output'], $m);
    return ['installed' => $installed, 'version' => $m[1] ?? null];
}

function checkDockerCompose() {
    if (!canExec()) return ['installed' => false, 'version' => null];
    // Try new docker compose
    $r = execCmd('docker compose version');
    if ($r['code'] === 0 && strpos($r['output'], 'version') !== false) {
        preg_match('/v?([0-9.]+)/', $r['output'], $m);
        return ['installed' => true, 'version' => $m[1] ?? 'unknown', 'cmd' => 'docker compose'];
    }
    // Try old docker-compose
    $r = execCmd('docker-compose --version');
    if ($r['code'] === 0) {
        preg_match('/([0-9.]+)/', $r['output'], $m);
        return ['installed' => true, 'version' => $m[1] ?? 'unknown', 'cmd' => 'docker-compose'];
    }
    return ['installed' => false, 'version' => null, 'cmd' => null];
}

function checkGPU() {
    if (!canExec()) return ['available' => false, 'name' => null];
    $r = execCmd('nvidia-smi --query-gpu=name --format=csv,noheader');
    if ($r['code'] === 0 && !empty(trim($r['output'])) && strpos($r['output'], 'NVIDIA') !== false) {
        return ['available' => true, 'name' => trim($r['output'])];
    }
    return ['available' => false, 'name' => null];
}

function getSystemRAM() {
    if (PHP_OS_FAMILY === 'Linux') {
        $mem = @file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/', $mem, $m)) {
            return round($m[1] / 1024 / 1024, 1);
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $r = execCmd('sysctl -n hw.memsize');
        if ($r['code'] === 0) return round((int)$r['output'] / 1024 / 1024 / 1024, 1);
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $r = execCmd('wmic computersystem get TotalPhysicalMemory');
        if (preg_match('/(\d+)/', $r['output'], $m)) {
            return round($m[1] / 1024 / 1024 / 1024, 1);
        }
    }
    return 0;
}

function checkRedisRunning($host = 'localhost', $port = 6379) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($sock) { fclose($sock); return true; }
    return false;
}

function checkRedisInstalled() {
    if (!canExec()) return false;
    $r = execCmd('which redis-server');
    return $r['code'] === 0 && !empty(trim($r['output']));
}
function installDocker() {
    $scriptPath = __DIR__ . '/install-docker.sh';
    if (!file_exists($scriptPath)) {
        return ['success' => false, 'log' => ['Error: install-docker.sh not found'], 'error' => 'Installation script missing'];
    }
    $log = [];
    $log[] = '🐳 Starting Docker installation...';
    $log[] = '📍 Detected OS: ' . getOS();
    $log[] = '';
    $cmd = 'sudo bash ' . escapeshellarg($scriptPath) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $log = array_merge($log, $output);
    if ($code === 0) {
        $log[] = '';
        $log[] = '✅ Docker installation completed!';
        return ['success' => true, 'log' => $log];
    } else {
        $log[] = '';
        $log[] = '❌ Installation failed with exit code: ' . $code;
        return ['success' => false, 'log' => $log, 'error' => 'Installation failed'];
    }
}



function getOS() {
    if (stripos(PHP_OS, 'darwin') !== false) return 'macos';
    if (stripos(PHP_OS, 'win') !== false) return 'windows';
    if (stripos(PHP_OS, 'linux') !== false) {
        // Check distro
        if (file_exists('/etc/debian_version')) return 'debian';
        if (file_exists('/etc/redhat-release')) return 'rhel';
        if (file_exists('/etc/arch-release')) return 'arch';
        return 'linux';
    }
    return 'unknown';
}

// ═══════════════════════════════════════════════════════════════════════════
// SYSTEM REQUIREMENTS CHECK
// ═══════════════════════════════════════════════════════════════════════════

function runSystemChecks() {
    $checks = [];
    
    // PHP Version
    $checks['php'] = [
        'name' => 'PHP Version',
        'required' => MIN_PHP . '+',
        'found' => PHP_VERSION,
        'pass' => version_compare(PHP_VERSION, MIN_PHP, '>='),
        'critical' => true
    ];
    
    // exec() function
    $checks['exec'] = [
        'name' => 'exec() Function',
        'required' => 'Enabled',
        'found' => canExec() ? 'Enabled' : 'Disabled',
        'pass' => canExec(),
        'critical' => true,
        'help' => 'Enable exec() in php.ini by removing it from disable_functions'
    ];
    
    // ZipArchive
    $checks['zip'] = [
        'name' => 'ZipArchive Extension',
        'required' => 'Installed',
        'found' => class_exists('ZipArchive') ? 'Available' : 'Missing',
        'pass' => class_exists('ZipArchive'),
        'critical' => true,
        'help' => 'Install php-zip extension'
    ];
    
    // Docker
    $docker = checkDocker();
    $checks['docker'] = [
        'name' => 'Docker',
        'required' => 'Installed',
        'found' => $docker['installed'] ? 'v' . $docker['version'] : 'Not found',
        'pass' => $docker['installed'],
        'critical' => true,
        'help' => 'Install Docker from https://docker.com'
    ];
    
    // Docker Compose
    $compose = checkDockerCompose();
    $checks['compose'] = [
        'name' => 'Docker Compose',
        'required' => 'Installed',
        'found' => $compose['installed'] ? 'v' . $compose['version'] : 'Not found',
        'pass' => $compose['installed'],
        'critical' => true,
        'cmd' => $compose['cmd'] ?? 'docker compose',
        'help' => 'Install Docker Compose from https://docs.docker.com/compose/install/'
    ];
    
    // System RAM
    $ram = getSystemRAM();
    $checks['ram'] = [
        'name' => 'System RAM',
        'required' => REC_RAM_GB . 'GB recommended',
        'found' => $ram > 0 ? $ram . ' GB' : 'Unknown',
        'pass' => $ram >= MIN_RAM_GB || $ram == 0,
        'warn' => $ram > 0 && $ram < REC_RAM_GB,
        'critical' => false
    ];
    
    // GPU (optional)
    $gpu = checkGPU();
    $checks['gpu'] = [
        'name' => 'NVIDIA GPU',
        'required' => 'Optional',
        'found' => $gpu['available'] ? $gpu['name'] : 'Not detected',
        'pass' => true,
        'optional' => true,
        'has_gpu' => $gpu['available']
    ];
    
    // Write permissions
    $path = dirname(__FILE__);
    $checks['write'] = [
        'name' => 'Write Permissions',
        'required' => 'Writable',
        'found' => is_writable($path) ? 'Writable' : 'Not writable',
        'pass' => is_writable($path),
        'critical' => true,
        'help' => 'chmod 755 ' . $path
    ];
    
    return $checks;
}

// ═══════════════════════════════════════════════════════════════════════════
// INSTALLATION FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

function extractFiles($targetDir) {
    $log = [];
    $zipFile = dirname(__FILE__) . '/app-bundle.zip';
    
    // Create directories
    $dirs = ['app', 'web', 'uploads', 'outputs', 'thumbnails', 'weights', 'redis-data'];
    foreach ($dirs as $dir) {
        $path = $targetDir . '/' . $dir;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                return ['success' => false, 'error' => "Failed to create directory: $dir", 'log' => $log];
            }
        }
    }
    $log[] = "✓ Created directories";
    
    // Extract ZIP if exists
    if (file_exists($zipFile)) {
        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            $zip->extractTo($targetDir);
            $zip->close();
            $log[] = "✓ Extracted app-bundle.zip";
        } else {
            return ['success' => false, 'error' => 'Failed to extract ZIP file', 'log' => $log];
        }
    } else {
        // Check if files already in place
        if (file_exists(dirname(__FILE__) . '/app/main.py')) {
            // Copy from app directory
            copy(dirname(__FILE__) . '/app/main.py', $targetDir . '/app/main.py');
            $log[] = "✓ Copied main.py";
        }
        if (file_exists(dirname(__FILE__) . '/web/index.html')) {
            copy(dirname(__FILE__) . '/web/index.html', $targetDir . '/web/index.html');
            $log[] = "✓ Copied web/index.html";
        }
    }
    
    return ['success' => true, 'log' => $log];
}

function generateDockerCompose($config) {
    $port = $config['port'];
    $redisMode = $config['redis_mode'];
    $redisHost = $config['redis_host'];
    $redisPort = $config['redis_port'];
    $hasGpu = $config['has_gpu'];
    
    $gpuBlock = $hasGpu ? "
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]" : "";
    
    $redisService = "";
    $redisEnv = "";
    $depends = "";
    
    if ($redisMode === 'bundled') {
        $redisService = "

  mega-upscaler-redis:
    image: redis:7-alpine
    container_name: mega-upscaler-redis
    command: redis-server --appendonly yes
    volumes:
      - ./redis-data:/data
    restart: unless-stopped
    healthcheck:
      test: [\"CMD\", \"redis-cli\", \"ping\"]
      interval: 10s
      timeout: 5s
      retries: 3";
        $redisEnv = "
      - REDIS_HOST=mega-upscaler-redis
      - REDIS_PORT=6379";
        $depends = "
    depends_on:
      mega-upscaler-redis:
        condition: service_healthy";
    } elseif ($redisMode === 'external') {
        $redisEnv = "
      - REDIS_HOST={$redisHost}
      - REDIS_PORT={$redisPort}";
    } elseif ($redisMode === 'native') {
        $redisEnv = "
      - REDIS_HOST=host.docker.internal
      - REDIS_PORT={$redisPort}";
    }
    // 'none' mode = no redis env vars, app falls back to memory storage

    return "version: '3.8'

services:
  mega-upscaler:
    build: .
    container_name: mega-upscaler
    ports:
      - \"{$port}:8000\"
    volumes:
      - ./uploads:/app/uploads
      - ./outputs:/app/outputs
      - ./thumbnails:/app/thumbnails
      - ./weights:/app/weights
      - ./web:/app/web
    environment:
      - PYTHONUNBUFFERED=1{$redisEnv}
    restart: unless-stopped{$depends}{$gpuBlock}
{$redisService}

networks:
  default:
    name: mega-upscaler-net
";
}

function generateDockerfile() {
    return '# MEGA UPSCALER v3.2 - Dockerfile
FROM python:3.11-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \\
    libgl1 \\
    libglib2.0-0 \\
    wget \\
    && rm -rf /var/lib/apt/lists/*

# Copy and install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application
COPY app/main.py ./main.py
COPY web ./web

# Create directories
RUN mkdir -p uploads outputs thumbnails weights

EXPOSE 8000

CMD ["python", "main.py"]
';
}

function generateRequirements() {
    return "# MEGA UPSCALER v3.2 - Python Dependencies
fastapi>=0.109.0
uvicorn>=0.27.0
python-multipart>=0.0.6
pillow>=10.0.0
numpy>=1.24.0
redis>=5.0.0
# Optional: Uncomment for AI upscaling (requires NVIDIA GPU)
# realesrgan
# basicsr
# torch
# torchvision
";
}

function installNativeRedis() {
    $os = getOS();
    $log = [];
    
    switch ($os) {
        case 'debian':
            $log[] = "Installing Redis on Debian/Ubuntu...";
            execCmd('sudo apt-get update');
            $r = execCmd('sudo apt-get install -y redis-server');
            if ($r['code'] !== 0) {
                return ['success' => false, 'error' => 'Failed to install redis-server', 'log' => $log];
            }
            execCmd('sudo systemctl enable redis-server');
            execCmd('sudo systemctl start redis-server');
            $log[] = "✓ Redis installed and started";
            break;
            
        case 'rhel':
            $log[] = "Installing Redis on RHEL/CentOS...";
            $r = execCmd('sudo yum install -y redis');
            if ($r['code'] !== 0) {
                return ['success' => false, 'error' => 'Failed to install redis', 'log' => $log];
            }
            execCmd('sudo systemctl enable redis');
            execCmd('sudo systemctl start redis');
            $log[] = "✓ Redis installed and started";
            break;
            
        case 'arch':
            $log[] = "Installing Redis on Arch Linux...";
            $r = execCmd('sudo pacman -S --noconfirm redis');
            if ($r['code'] !== 0) {
                return ['success' => false, 'error' => 'Failed to install redis', 'log' => $log];
            }
            execCmd('sudo systemctl enable redis');
            execCmd('sudo systemctl start redis');
            $log[] = "✓ Redis installed and started";
            break;
            
        case 'macos':
            $log[] = "Installing Redis on macOS...";
            $r = execCmd('brew install redis');
            if ($r['code'] !== 0) {
                return ['success' => false, 'error' => 'Failed to install redis (is Homebrew installed?)', 'log' => $log];
            }
            execCmd('brew services start redis');
            $log[] = "✓ Redis installed and started";
            break;
            
        default:
            return ['success' => false, 'error' => "Unsupported OS: $os. Please install Redis manually.", 'log' => $log];
    }
    
    // Verify
    sleep(2);
    if (checkRedisRunning()) {
        $log[] = "✓ Redis is running on localhost:6379";
        return ['success' => true, 'log' => $log];
    } else {
        return ['success' => false, 'error' => 'Redis installed but not responding', 'log' => $log];
    }
}

function runInstallation($config) {
    $log = [];
    $targetDir = $config['install_path'];
    $composeCmd = $config['compose_cmd'] ?? 'docker compose';
    
    try {
        // 1. Extract files
        $log[] = "📦 Extracting files...";
        $result = extractFiles($targetDir);
        $log = array_merge($log, $result['log']);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'], 'log' => $log];
        }
        
        // 2. Install native Redis if requested
        if ($config['redis_mode'] === 'native' && !checkRedisRunning()) {
            $log[] = "🗄️ Installing Redis...";
            $result = installNativeRedis();
            $log = array_merge($log, $result['log']);
            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'], 'log' => $log];
            }
        }
        
        // 3. Generate config files
        $log[] = "⚙️ Generating configuration...";
        
        file_put_contents($targetDir . '/docker-compose.yml', generateDockerCompose($config));
        $log[] = "✓ Generated docker-compose.yml";
        
        file_put_contents($targetDir . '/Dockerfile', generateDockerfile());
        $log[] = "✓ Generated Dockerfile";
        
        file_put_contents($targetDir . '/requirements.txt', generateRequirements());
        $log[] = "✓ Generated requirements.txt";
        
        // 4. Copy main.py if not already in app/
        if (!file_exists($targetDir . '/app/main.py')) {
            if (file_exists(dirname(__FILE__) . '/app/main.py')) {
                copy(dirname(__FILE__) . '/app/main.py', $targetDir . '/app/main.py');
                $log[] = "✓ Copied application code";
            }
        }
        
        // 5. Copy web files if not already there
        if (!file_exists($targetDir . '/web/index.html')) {
            if (file_exists(dirname(__FILE__) . '/web/index.html')) {
                copy(dirname(__FILE__) . '/web/index.html', $targetDir . '/web/index.html');
                $log[] = "✓ Copied web interface";
            }
        }
        
        // 6. Build Docker image
        $log[] = "🔨 Building Docker image (this may take a few minutes)...";
        $oldDir = getcwd();
        chdir($targetDir);
        
        $r = execCmd($composeCmd . ' build');
        if ($r['code'] !== 0) {
            chdir($oldDir);
            return ['success' => false, 'error' => "Docker build failed:\n" . $r['output'], 'log' => $log];
        }
        $log[] = "✓ Docker image built";
        
        // 7. Start containers
        $log[] = "🚀 Starting containers...";
        $r = execCmd($composeCmd . ' up -d');
        if ($r['code'] !== 0) {
            chdir($oldDir);
            return ['success' => false, 'error' => "Docker start failed:\n" . $r['output'], 'log' => $log];
        }
        $log[] = "✓ Containers started";
        
        chdir($oldDir);
        
        // 8. Wait for service
        $log[] = "⏳ Waiting for service to be ready...";
        sleep(5);
        
        // Check if running
        $r = execCmd('docker ps --filter name=mega-upscaler --format "{{.Status}}"');
        if (strpos($r['output'], 'Up') !== false) {
            $log[] = "✓ Service is running!";
        }
        
        $url = 'http://localhost:' . $config['port'];
        $log[] = "🎉 Installation complete!";
        $log[] = "🔗 Access URL: $url";
        
        return [
            'success' => true,
            'log' => $log,
            'url' => $url
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'log' => $log];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// FORM PROCESSING
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2:
            $_SESSION['checks'] = runSystemChecks();
            header('Location: install.php?step=3');
            exit;
            
        case 3:
            $_SESSION['redis_mode'] = $_POST['redis_mode'] ?? 'bundled';
            $_SESSION['redis_host'] = $_POST['redis_host'] ?? 'localhost';
            $_SESSION['redis_port'] = $_POST['redis_port'] ?? '6379';
            header('Location: install.php?step=4');
            exit;
            
        case 4:
            $_SESSION['port'] = $_POST['port'] ?? '15073';
            $_SESSION['install_path'] = rtrim($_POST['install_path'] ?? dirname(__FILE__), '/');
            header('Location: install.php?step=5');
            exit;
            
        case 5:
            $compose = checkDockerCompose();
            $gpu = checkGPU();
            
            $config = [
                'install_path' => $_SESSION['install_path'] ?? dirname(__FILE__),
                'port' => $_SESSION['port'] ?? '15073',
                'redis_mode' => $_SESSION['redis_mode'] ?? 'bundled',
                'redis_host' => $_SESSION['redis_host'] ?? 'localhost',
                'redis_port' => $_SESSION['redis_port'] ?? '6379',
                'compose_cmd' => $compose['cmd'] ?? 'docker compose',
                'has_gpu' => $gpu['available']
            ];
            
            $result = runInstallation($config);
            $_SESSION['install_result'] = $result;
            
            if ($result['success']) {
                header('Location: install.php?step=6');
                exit;
            } else {
                $errors[] = $result['error'];
            }
            break;
    }
}

// Run checks for step 2
if ($step === 2 && empty($_SESSION['checks'])) {
    $_SESSION['checks'] = runSystemChecks();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Installer</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0a0015;
            --bg-mid: #1a0030;
            --pink: #f0f;
            --cyan: #0ff;
            --green: #0f0;
            --red: #f44;
            --yellow: #ff0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--bg-dark) 0%, var(--bg-mid) 50%, var(--bg-dark) 100%);
            font-family: 'Rajdhani', sans-serif;
            color: #fff;
            line-height: 1.6;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .installer-card {
            background: rgba(0,0,0,0.6);
            border: 1px solid rgba(255,0,255,0.3);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 0 60px rgba(255,0,255,0.15);
        }
        
        /* Header */
        .header {
            background: linear-gradient(90deg, rgba(255,0,255,0.15), rgba(0,255,255,0.15));
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255,0,255,0.2);
        }
        
        .logo {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.2rem;
            font-weight: 900;
            background: linear-gradient(90deg, var(--pink), var(--cyan));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }
        
        .tagline {
            color: var(--cyan);
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        /* Progress Steps */
        .steps-bar {
            display: flex;
            background: rgba(0,0,0,0.4);
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            overflow-x: auto;
        }
        
        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px;
            color: #555;
            font-size: 0.8rem;
            min-width: 90px;
            position: relative;
        }
        
        .step-item.active { color: var(--cyan); }
        .step-item.done { color: var(--green); }
        
        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            font-size: 1.2rem;
            transition: all 0.3s;
        }
        
        .step-item.active .step-icon {
            background: linear-gradient(135deg, var(--pink), var(--cyan));
            box-shadow: 0 0 20px rgba(0,255,255,0.4);
        }
        
        .step-item.done .step-icon {
            background: var(--green);
        }
        
        /* Content */
        .content {
            padding: 40px;
        }
        
        .content h2 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.6rem;
            color: var(--pink);
            margin-bottom: 15px;
        }
        
        .content p {
            color: #aaa;
            margin-bottom: 20px;
        }
        
        /* Feature Grid */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin: 30px 0;
        }
        
        .feature {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .feature-icon { font-size: 2rem; margin-bottom: 10px; }
        .feature-title { color: var(--cyan); font-weight: bold; margin-bottom: 5px; }
        .feature-desc { color: #777; font-size: 0.85rem; }
        
        /* Check List */
        .check-list { margin: 25px 0; }
        
        .check-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
            margin-bottom: 10px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        
        .check-status {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        
        .check-status.pass { background: rgba(0,255,0,0.15); color: var(--green); }
        .check-status.fail { background: rgba(255,0,0,0.15); color: var(--red); }
        .check-status.warn { background: rgba(255,255,0,0.15); color: var(--yellow); }
        
        .check-info { flex: 1; }
        .check-name { font-weight: bold; color: #fff; }
        .check-detail { font-size: 0.85rem; color: #666; }
        .check-detail span { color: var(--cyan); }
        .check-help { font-size: 0.8rem; color: var(--yellow); margin-top: 5px; }
        
        /* Radio Options */
        .radio-group { margin: 20px 0; }
        
        .radio-option {
            display: flex;
            align-items: flex-start;
            padding: 18px;
            background: rgba(255,255,255,0.03);
            border: 2px solid transparent;
            border-radius: 12px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .radio-option:hover { border-color: rgba(0,255,255,0.3); }
        .radio-option.selected { 
            border-color: var(--cyan); 
            background: rgba(0,255,255,0.08);
        }
        
        .radio-option input[type="radio"] {
            margin-right: 15px;
            margin-top: 4px;
            accent-color: var(--cyan);
        }
        
        .radio-content { flex: 1; }
        .radio-title { font-weight: bold; color: #fff; margin-bottom: 5px; }
        .radio-desc { color: #888; font-size: 0.9rem; }
        
        .sub-options {
            margin-top: 15px;
            padding: 15px;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            display: none;
        }
        
        .sub-options.show { display: block; }
        
        /* Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group { margin-bottom: 20px; }
        
        .form-group label {
            display: block;
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0,0,0,0.5);
            border: 1px solid rgba(255,0,255,0.3);
            border-radius: 8px;
            color: #fff;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--cyan);
        }
        
        /* Buttons */
        .btn-row {
            display: flex;
            gap: 15px;
            margin-top: 35px;
        }
        
        .btn {
            padding: 16px 32px;
            font-size: 1rem;
            font-weight: bold;
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 0.05em;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
            text-decoration: none;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--pink), var(--cyan));
            color: #000;
        }
        
        .btn-primary:hover {
            box-shadow: 0 0 35px rgba(255,0,255,0.5);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.15);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Messages */
        .error-box {
            background: rgba(255,0,0,0.15);
            border: 1px solid var(--red);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 25px;
            color: #faa;
        }
        
        /* Log Box */
        .log-box {
            background: rgba(0,0,0,0.6);
            border-radius: 10px;
            padding: 20px;
            font-family: 'Consolas', monospace;
            font-size: 0.9rem;
            max-height: 350px;
            overflow-y: auto;
            margin: 20px 0;
        }
        
        .log-line {
            padding: 6px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: #aaa;
        }
        
        .log-line:last-child { border-bottom: none; }
        
        /* Progress Animation */
        .progress-container {
            margin: 30px 0;
        }
        
        .progress-bar {
            height: 24px;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            width: 40%;
            background: linear-gradient(90deg, var(--pink), var(--cyan), var(--pink));
            background-size: 200% 100%;
            animation: progressMove 2s linear infinite;
            border-radius: 12px;
        }
        
        @keyframes progressMove {
            0% { margin-left: 0%; background-position: 0% 0%; }
            50% { margin-left: 60%; background-position: 100% 0%; }
            100% { margin-left: 0%; background-position: 0% 0%; }
        }
        
        .progress-text {
            text-align: center;
            margin-top: 15px;
            color: var(--cyan);
            font-size: 0.95rem;
        }
        
        /* Success Screen */
        .success-box {
            text-align: center;
            padding: 50px 20px;
        }
        
        .success-icon {
            font-size: 5rem;
            margin-bottom: 25px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .success-url {
            display: inline-block;
            padding: 18px 35px;
            background: rgba(0,255,0,0.15);
            border: 2px solid var(--green);
            border-radius: 12px;
            color: var(--green);
            font-size: 1.3rem;
            font-family: 'Orbitron', sans-serif;
            text-decoration: none;
            margin: 25px 0;
            transition: all 0.3s;
        }
        
        .success-url:hover {
            background: rgba(0,255,0,0.25);
            box-shadow: 0 0 30px rgba(0,255,0,0.3);
        }
        
        .cleanup-note {
            margin-top: 30px;
            padding: 15px;
            background: rgba(255,255,0,0.1);
            border-radius: 8px;
            color: var(--yellow);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="installer-card">
            <div class="header">
                <div class="logo">🖼️ <?= APP_NAME ?></div>
                <div class="tagline">Installation Wizard • v<?= APP_VERSION ?></div>
            </div>
            
            <div class="steps-bar">
                <?php foreach ($STEPS as $num => $s): ?>
                <div class="step-item <?= $num < $step ? 'done' : ($num === $step ? 'active' : '') ?>">
                    <div class="step-icon"><?= $num < $step ? '✓' : $s['icon'] ?></div>
                    <span><?= $s['name'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="content">
                <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $e): ?>
                    <p>❌ <?= htmlspecialchars($e) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 1: WELCOME
                      // ════════════════════════════════════════════════════════════
                if ($step === 1): ?>
                
                <h2>Welcome to <?= APP_NAME ?></h2>
                <p>This wizard will install <?= APP_NAME ?>, an AI-powered image upscaling system designed for large format printing. Transform any image into massive, print-ready files up to <strong>6+ gigapixels</strong>.</p>
                
                <div class="features">
                    <div class="feature">
                        <div class="feature-icon">🤖</div>
                        <div class="feature-title">AI Upscaling</div>
                        <div class="feature-desc">Real-ESRGAN neural network for superior quality</div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">📐</div>
                        <div class="feature-title">Huge Output</div>
                        <div class="feature-desc">Up to 140,000 × 43,000 px (6.06 GP)</div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">💾</div>
                        <div class="feature-title">Memory Safe</div>
                        <div class="feature-desc">Chunked processing handles any image size</div>
                    </div>
                    <div class="feature">
                        <div class="feature-icon">🎨</div>
                        <div class="feature-title">Easy Interface</div>
                        <div class="feature-desc">Drag-and-drop web UI with live progress</div>
                    </div>
                </div>
                
                <p><strong>Requirements:</strong></p>
                <ul style="color:#aaa; margin-left:20px; margin-bottom:20px;">
                    <li>Docker & Docker Compose</li>
                    <li>8GB+ RAM (16GB recommended)</li>
                    <li>NVIDIA GPU (optional, for AI acceleration)</li>
                </ul>
                
                <div class="btn-row">
                    <a href="?step=2" class="btn btn-primary">Begin Installation →</a>
                </div>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 2: SYSTEM CHECK
                      // ════════════════════════════════════════════════════════════
                elseif ($step === 2): 
                    // Show Docker installation result if attempted
                    if (isset($_SESSION['docker_install_result'])):
                        $dockerResult = $_SESSION['docker_install_result'];
                        unset($_SESSION['docker_install_result']);
                ?>
                <div class="<?= $dockerResult['success'] ? 'success-box' : 'error-box' ?>" style="margin-bottom: 20px; padding: 15px; border-radius: 8px;">
                    <h3 style="margin-bottom: 10px;"><?= $dockerResult['success'] ? '✅ Docker Installed!' : '❌ Installation Issue' ?></h3>
                    <div class="log-box" style="max-height: 200px; overflow-y: auto; font-size: 0.85rem; background: rgba(0,0,0,0.3); padding: 10px; border-radius: 4px;">
                        <?php foreach ($dockerResult['log'] as $line): ?>
                        <div style="font-family: monospace;"><?= htmlspecialchars($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($dockerResult['success']): ?>
                    <p style="margin-top: 10px; color: #10b981;">You may need to <strong>log out and back in</strong> for Docker permissions to take effect, then refresh this page.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php
                    $checks = $_SESSION['checks'] ?? runSystemChecks();
                    $_SESSION['checks'] = $checks;
                    $canProceed = true;
                    foreach ($checks as $c) {
                        if (!empty($c['critical']) && !$c['pass']) $canProceed = false;
                    }
                ?>
                
                <h2>System Requirements</h2>
                <p>Checking your system for required components...</p>
                
                <div class="check-list">
                    <?php foreach ($checks as $key => $c): 
                        $status = $c['pass'] ? (isset($c['warn']) && $c['warn'] ? 'warn' : 'pass') : 'fail';
                        $icon = $status === 'pass' ? '✓' : ($status === 'warn' ? '⚠' : '✗');
                    ?>
                    <div class="check-item">
                        <div class="check-status <?= $status ?>"><?= $icon ?></div>
                        <div class="check-info">
                            <div class="check-name"><?= $c['name'] ?><?= !empty($c['optional']) ? ' (Optional)' : '' ?></div>
                            <div class="check-detail">
                                Required: <?= $c['required'] ?> • Found: <span><?= $c['found'] ?></span>
                            </div>
                            <?php if (!$c['pass'] && !empty($c['help'])): ?>
                            <div class="check-help">💡 <?= $c['help'] ?></div>
                            <?php endif; ?>
                            <?php if ($key === 'docker' && !$c['pass']): ?>
                            <form method="post" style="margin-top: 10px;">
                                <button type="submit" name="install_docker" value="1" class="btn btn-sm" style="background: linear-gradient(135deg, #10b981, #059669); padding: 8px 16px; font-size: 0.85rem;">
                                    🔧 Auto-Install Docker
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <form method="post">
                    <div class="btn-row">
                        <a href="?step=1" class="btn btn-secondary">← Back</a>
                        <button type="submit" class="btn btn-primary" <?= !$canProceed ? 'disabled' : '' ?>>
                            <?= $canProceed ? 'Continue →' : 'Fix Issues First' ?>
                        </button>
                    </div>
                </form>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 3: REDIS SETUP
                      // ════════════════════════════════════════════════════════════
                elseif ($step === 3): 
                    $redisRunning = checkRedisRunning();
                    $redisInstalled = checkRedisInstalled();
                ?>
                
                <h2>Redis Configuration</h2>
                <p>Redis is used to persist job state across restarts. Choose your preferred setup:</p>
                
                <form method="post" id="redis-form">
                    <div class="radio-group">
                        <label class="radio-option selected" onclick="selectRedis('bundled')">
                            <input type="radio" name="redis_mode" value="bundled" checked>
                            <div class="radio-content">
                                <div class="radio-title">📦 Bundled Redis Container (Recommended)</div>
                                <div class="radio-desc">Install a dedicated Redis container alongside the app. Fully isolated and automatic.</div>
                            </div>
                        </label>
                        
                        <?php if (!$redisRunning): ?>
                        <label class="radio-option" onclick="selectRedis('native')">
                            <input type="radio" name="redis_mode" value="native">
                            <div class="radio-content">
                                <div class="radio-title">🔧 Install Redis Natively</div>
                                <div class="radio-desc">Install Redis directly on your system (apt/yum/brew). Shares one Redis instance across apps.</div>
                            </div>
                        </label>
                        <?php endif; ?>
                        
                        <label class="radio-option" onclick="selectRedis('external')">
                            <input type="radio" name="redis_mode" value="external">
                            <div class="radio-content">
                                <div class="radio-title">🔗 Use Existing Redis Server</div>
                                <div class="radio-desc">Connect to a Redis server already running on your network.</div>
                                <?php if ($redisRunning): ?>
                                <div style="color:var(--green); margin-top:8px;">✓ Redis detected on localhost:6379</div>
                                <?php endif; ?>
                            </div>
                        </label>
                        
                        <div class="sub-options" id="external-opts">
                            <div class="form-row">
                                <div class="form-group" style="margin-bottom:0">
                                    <label>Redis Host</label>
                                    <input type="text" name="redis_host" value="<?= $redisRunning ? 'localhost' : '' ?>" placeholder="localhost or IP">
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label>Redis Port</label>
                                    <input type="number" name="redis_port" value="6379" placeholder="6379">
                                </div>
                            </div>
                        </div>
                        
                        <label class="radio-option" onclick="selectRedis('none')">
                            <input type="radio" name="redis_mode" value="none">
                            <div class="radio-content">
                                <div class="radio-title">❌ No Redis</div>
                                <div class="radio-desc">Jobs are stored in memory only. <strong>Will be lost on restart.</strong></div>
                            </div>
                        </label>
                    </div>
                    
                    <div class="btn-row">
                        <a href="?step=2" class="btn btn-secondary">← Back</a>
                        <button type="submit" class="btn btn-primary">Continue →</button>
                    </div>
                </form>
                
                <script>
                function selectRedis(mode) {
                    document.querySelectorAll('.radio-option').forEach(o => o.classList.remove('selected'));
                    event.currentTarget.classList.add('selected');
                    document.getElementById('external-opts').classList.toggle('show', mode === 'external');
                }
                </script>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 4: CONFIGURATION
                      // ════════════════════════════════════════════════════════════
                elseif ($step === 4): ?>
                
                <h2>Application Configuration</h2>
                <p>Configure your installation settings:</p>
                
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Web Interface Port</label>
                            <input type="number" name="port" value="15073" min="1024" max="65535">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Installation Directory</label>
                        <input type="text" name="install_path" value="<?= htmlspecialchars(dirname(__FILE__)) ?>">
                    </div>
                    
                    <p style="margin-top:30px; color:#888; font-size:0.9rem;">
                        <strong>Note:</strong> Default target dimensions (39ft × 12ft @ 300 PPI) can be changed in the web interface after installation.
                    </p>
                    
                    <div class="btn-row">
                        <a href="?step=3" class="btn btn-secondary">← Back</a>
                        <button type="submit" class="btn btn-primary">Install Now →</button>
                    </div>
                </form>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 5: INSTALLATION
                      // ════════════════════════════════════════════════════════════
                elseif ($step === 5): ?>
                
                <h2>Installing...</h2>
                <p>Please wait while we set up <?= APP_NAME ?>. This may take several minutes for the Docker build.</p>
                
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">Building Docker image and starting services...</div>
                </div>
                
                <div class="log-box" id="log">
                    <div class="log-line">Initializing installation...</div>
                </div>
                
                <form method="post" id="install-form">
                    <input type="hidden" name="do_install" value="1">
                </form>
                
                <script>
                setTimeout(function() {
                    document.getElementById('install-form').submit();
                }, 800);
                </script>
                
                <?php // ════════════════════════════════════════════════════════════
                      // STEP 6: COMPLETE
                      // ════════════════════════════════════════════════════════════
                elseif ($step === 6): 
                    $result = $_SESSION['install_result'] ?? ['success' => false];
                ?>
                
                <?php if ($result['success']): ?>
                <div class="success-box">
                    <div class="success-icon">🎉</div>
                    <h2>Installation Complete!</h2>
                    <p style="color:#aaa;"><?= APP_NAME ?> has been successfully installed and is running.</p>
                    
                    <a href="<?= $result['url'] ?>" target="_blank" class="success-url">
                        🚀 Launch <?= APP_NAME ?>
                    </a>
                    
                    <div class="log-box" style="text-align:left;">
                        <?php foreach ($result['log'] as $line): ?>
                        <div class="log-line"><?= htmlspecialchars($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="cleanup-note">
                        ⚠️ <strong>Security:</strong> Delete this install.php file after installation to prevent unauthorized reinstalls.
                    </div>
                </div>
                <?php else: ?>
                <div class="error-box">
                    <h3>Installation Failed</h3>
                    <p><?= htmlspecialchars($result['error'] ?? 'Unknown error occurred') ?></p>
                </div>
                <?php if (!empty($result['log'])): ?>
                <div class="log-box">
                    <?php foreach ($result['log'] as $line): ?>
                    <div class="log-line"><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="btn-row">
                    <a href="?step=4" class="btn btn-secondary">← Try Again</a>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
