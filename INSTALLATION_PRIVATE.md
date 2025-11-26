# Installing Blockchain Attendance System (Private Repository)

Since this is a private repository, users need to authenticate with GitHub to install.

## Installation Steps

### 1. Generate GitHub Personal Access Token

1. Go to https://github.com/settings/tokens
2. Click **"Generate new token"** → **"Generate new token (classic)"**
3. Give it a name: `Composer Access`
4. Select scopes: **`repo`** (Full control of private repositories)
5. Click **"Generate token"**
6. **Copy the token** (you won't see it again!)

### 2. Configure Composer Authentication

Run this command with your token:

```bash
composer config --global github-oauth.github.com YOUR_GITHUB_TOKEN_HERE
```

### 3. Install the Package

#### Option A: Create New Project
```bash
composer create-project mavis-creator/blockchain-attendance-system my-attendance --repository='{"type":"vcs","url":"https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System.git"}'
```

#### Option B: Add to Existing Project

Add to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System.git"
        }
    ],
    "require": {
        "mavis-creator/blockchain-attendance-system": "^2.0"
    }
}
```

Then run:
```bash
composer install
```

### 4. Configure Environment

```bash
cd my-attendance
cp .env.example .env
# Edit .env with your settings
```

### 5. Start the Application

```bash
php -S localhost:8000
```

Visit: http://localhost:8000

---

## Alternative: Manual Installation

If you don't want to use Composer authentication:

```bash
git clone https://github.com/MAVIS-creator/Blockchain-Based-Attendance-System.git
cd Blockchain-Based-Attendance-System
composer install
cp .env.example .env
# Configure .env
php -S localhost:8000
```

---

## Troubleshooting

### "Repository not found" error

Make sure you:
1. Have access to the private repository
2. Configured GitHub token correctly
3. Token has `repo` scope enabled

### Re-configure token

```bash
composer config --global --unset github-oauth.github.com
composer config --global github-oauth.github.com NEW_TOKEN_HERE
```

---

## For Public Release

To make this package available on Packagist.org (free):
1. Make the repository public on GitHub
2. Submit to https://packagist.org/packages/submit
3. Users can install with: `composer create-project mavis-creator/blockchain-attendance-system`
