# Build Instructions

This document explains how to create a release-ready package for WordPress.org submission.

## Build Scripts Available

Three build scripts are provided for different platforms:

### 1. **build.ps1** (Windows - Recommended)
PowerShell script for Windows users.

**Usage:**
```powershell
powershell -ExecutionPolicy Bypass -File build.ps1
```

**Requirements:**
- Windows PowerShell 5.0+
- No additional dependencies

### 2. **build.sh** (Linux/Mac)
Bash script for Unix-based systems.

**Usage:**
```bash
chmod +x build.sh
./build.sh
```

**Requirements:**
- Bash shell
- `zip` command

### 3. **build.bat** (Windows - Alternative)
Batch file for Windows (fallback option).

**Usage:**
```cmd
build.bat
```

**Requirements:**
- Windows Command Prompt
- PowerShell (for zip creation)

## What the Build Script Does

1. **Extracts Version** - Reads version from `block-usage-finder.php`
2. **Creates Build Directory** - Sets up `build/block-usage-finder/`
3. **Copies Files:**
   - `block-usage-finder.php` (main plugin file)
   - `README.md` (GitHub readme)
   - `LICENSE` (GPL v2 license)
   - `CHANGELOG.md` (version history)
4. **Generates `readme.txt`** - WordPress.org compatible readme
5. **Creates `.distignore`** - Excludes dev files from package
6. **Creates ZIP** - `block-usage-finder-{version}.zip`

## Output

The build creates:
- `build/` - Temporary build directory
- `block-usage-finder-{version}.zip` - Release package (~18 KB)

## Files Included in Release

```
block-usage-finder/
├── block-usage-finder.php  (Main plugin file)
├── README.md               (Documentation)
├── readme.txt              (WordPress.org format)
├── CHANGELOG.md            (Version history)
├── LICENSE                 (GPL v2)
└── .distignore            (Deployment exclusions)
```

## Files Excluded from Release

The `.distignore` file excludes:
- `.git/` - Git repository
- `.github/` - GitHub workflows
- `.claude/` - Claude AI files
- `build/` - Build directory
- `node_modules/` - Dependencies
- `CLAUDE.md` - Development docs
- `SECURITY.md` - Security details
- `*.zip` - Previous builds
- Build scripts (`build.sh`, `build.bat`, `build.ps1`)

## Testing the Build

Before submitting to WordPress.org:

1. **Install the plugin:**
   ```
   wp-content/plugins/block-usage-finder-2.0.0.zip
   ```

2. **Test functionality:**
   - Block search
   - Pattern search
   - CSV export
   - Post type filtering
   - WP-CLI commands

3. **Run plugin-check:**
   ```bash
   wp plugin-check block-usage-finder
   ```

4. **Verify readme.txt:**
   - Check `build/block-usage-finder/readme.txt`
   - Validate at: https://wordpress.org/plugins/developers/readme-validator/

## WordPress.org Submission

1. Create account at https://wordpress.org/plugins/developers/add/
2. Upload `block-usage-finder-2.0.0.zip`
3. Submit for review
4. Wait for approval (typically 3-7 days)

## Troubleshooting

### "Cannot be loaded because running scripts is disabled"
**Windows PowerShell:**
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

### "Permission denied: build.sh"
**Linux/Mac:**
```bash
chmod +x build.sh
```

### "zip: command not found"
**Linux:**
```bash
sudo apt install zip  # Debian/Ubuntu
sudo yum install zip  # CentOS/RHEL
```

### Build creates wrong version
Edit the version in `block-usage-finder.php`:
```php
* Version:     2.0.0
```

## Version Management

To release a new version:

1. Update version in `block-usage-finder.php`:
   ```php
   * Version:     2.1.0
   ```

2. Update `CHANGELOG.md` with new features

3. Run build script:
   ```powershell
   powershell -ExecutionPolicy Bypass -File build.ps1
   ```

4. This creates `block-usage-finder-2.1.0.zip`

## Clean Build

To start fresh:
```powershell
# Windows
Remove-Item -Recurse -Force build
Remove-Item block-usage-finder-*.zip

# Linux/Mac
rm -rf build block-usage-finder-*.zip
```

## Support

For build issues:
- Check that all required files exist
- Verify file permissions (Linux/Mac)
- Ensure PowerShell/Bash is available
- Check version format in plugin file

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html
