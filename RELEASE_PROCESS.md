# Shortcode to Blocks Pro - Release Process

## 📦 How to Release a New Version

### 1. **Update Version Numbers**

Edit these files and bump the version:

```php
// shortcode-to-blocks-pro.php (lines 4 and 17)
* Version: 1.1.0
define('STBP_VERSION', '1.1.0');
```

### 2. **Build the Production ZIP**

```bash
cd /Users/jhawkins/Documents/Workspace/plugins/shortcode-to-blocks-pro
composer install  # Install update checker library first
./build-release.sh
```

This creates: `dist/shortcode-to-blocks-pro.zip`

### 3. **Create GitHub Release**

1. **Push your changes:**
   ```bash
   git add .
   git commit -m "Release v1.1.0"
   git tag v1.1.0
   git push origin main
   git push origin v1.1.0
   ```

2. **Create release on GitHub:**
   - Go to: https://github.com/YOUR_USERNAME/shortcode-to-blocks-pro/releases
   - Click "Create a new release"
   - Tag: `v1.1.0`
   - Title: `Version 1.1.0`
   - Description: Release notes (what's new, bug fixes, etc.)
   - **Attach file:** Upload `dist/shortcode-to-blocks-pro.zip`
   - Click "Publish release"

### 4. **Users Get Notified Automatically**

Within 12 hours, WordPress will check for updates and show:
- Update notification in their plugin list
- One-click update button (if license is active)
- "View details" link showing your release notes

---

## 🔄 Update Flow for Customers

1. **Customer has active license** → See update notification → Click update → Done ✅
2. **Customer has expired license** → See notification but can't update → Prompted to renew

---

## ⚙️ First-Time Setup

### Install Composer Dependencies

```bash
cd /Users/jhawkins/Documents/Workspace/plugins/shortcode-to-blocks-pro
composer install
```

### Update GitHub Repo URL

Edit `includes/Updater.php` line 24 and replace with your actual repo:

```php
'https://github.com/YOUR_USERNAME/shortcode-to-blocks-pro',
```

### Create GitHub Repository

1. Create a **private** repo: `shortcode-to-blocks-pro`
2. Push your plugin code:
   ```bash
   cd /Users/jhawkins/Documents/Workspace/plugins/shortcode-to-blocks-pro
   git init
   git add .
   git commit -m "Initial commit"
   git remote add origin https://github.com/YOUR_USERNAME/shortcode-to-blocks-pro.git
   git push -u origin main
   ```

---

## 📝 Version Numbering

Follow semantic versioning:

- **1.0.0** → Initial release
- **1.0.1** → Bug fix release
- **1.1.0** → New features (backwards compatible)
- **2.0.0** → Breaking changes

---

## 🛡️ License Enforcement

Updates are **only available to users with active licenses**:
- Expired licenses see update notification but can't download
- Links point to https://shortcode-to-blocks.lemonsqueezy.com/billing to renew
- GitHub repo should be **private** to prevent unauthorized access

---

## 🧪 Testing Updates

To test the update system locally:

1. Install an old version on a test WordPress site
2. Activate with a valid license key
3. Create a new release on GitHub
4. Wait 12 hours OR force check: Go to Dashboard → Updates → Click "Check Again"
5. You should see the update notification
