# PHP via XAMPP (Windows) – so Cursor and the CLI see PHP

Use this if you don’t have PHP installed and want to use XAMPP so that:
- The terminal can run `php` and `composer`
- Cursor can validate/lint PHP and use the same PHP

---

## 1. Install XAMPP

1. Download the **PHP 8** build from: https://www.apachefriends.org/download.html  
   - Pick the version that includes **PHP 8.0** or **8.1** (e.g. “XAMPP for Windows” with PHP 8.x).
2. Run the installer.
3. Install to the default folder: **`C:\xampp`** (or note your chosen folder).
4. Finish the installer (you can skip starting the Control Panel for now).

---

## 2. Add PHP to Windows PATH

So that `php` and `composer` work in any terminal (including Cursor’s):

1. Press **Win + R**, type **`sysdm.cpl`**, Enter.
2. Open the **Advanced** tab → **Environment Variables**.
3. Under **System variables**, select **Path** → **Edit**.
4. Click **New** and add:
   - **`C:\xampp\php`**  
   (If you installed XAMPP elsewhere, use that path, e.g. `D:\xampp\php`.)
5. Confirm with **OK** on all windows.
6. **Close and reopen** Cursor (or at least all terminals) so the new PATH is loaded.

**Check:** In a new terminal run:
```powershell
php -v
```
You should see something like `PHP 8.x.x`.

---

## 3. Point Cursor at that PHP

The repo already has a workspace setting so Cursor uses XAMPP’s PHP:

- **File:** `c:\DEV\.vscode\settings.json`
- **Setting:** `"php.validate.executablePath": "C:\\xampp\\php\\php.exe"`

If your XAMPP is **not** in `C:\xampp`, edit that file and set the path to your `php.exe`, e.g.:

- `D:\xampp` → `"D:\\xampp\\php\\php.exe"`

After saving, Cursor will use that PHP for validation and tooling.

---

## 4. Install Composer (optional, for the test script)

1. Download: https://getcomposer.org/Composer-Setup.exe  
2. Run it; when it asks for “PHP executable”, choose **`C:\xampp\php\php.exe`**.  
3. Complete the install (add to PATH if offered).  
4. Restart the terminal and run:
   ```powershell
   composer --version
   ```

---

## 5. Run the plugin’s CLI test

From the plugin root:

```powershell
cd c:\DEV\hp-gmc-manager
composer install
php scripts/test-ads-create-list.php --credentials="C:\path\to\service-account.json" --developer-token=YOUR_TOKEN --customer-id=6629157252 --manager-id=6063247756
```

Replace the credentials path and token with your real values.

---

**Summary:** Install XAMPP → add `C:\xampp\php` to PATH → (optional) install Composer → ensure `c:\DEV\.vscode\settings.json` points at your `php.exe` so Cursor knows where PHP is.
