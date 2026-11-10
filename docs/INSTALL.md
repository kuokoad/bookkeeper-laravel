# Installing NunaBooks on the shop PC

This guide takes you through setting up NunaBooks on the shop's Windows PC, step by step. Do the steps in order and don't skip any. It takes about an hour.

You don't need to understand what each command does. Type it exactly as shown (or copy and paste it), press **Enter**, and wait until it finishes before you go on.

> **Before you go live:** the installer has not yet been run on a real shop PC. Do your first install on a spare PC, or on the shop PC outside trading hours. Keep the old app running until the new one has been checked (step 9).

---

## What you need

- The shop PC, running Windows 10 or 11, with an account that can **Run as Administrator**
- An internet connection for the install (you won't need it afterwards to make sales)
- A GitHub sign-in with access to the NunaBooks code, **or** a USB stick with the app folder on it
- A pen and paper (you'll write down a password)
- About 3 GB of free disk space

---

## Step 1. Install Laragon (this gives you PHP, MySQL and Composer)

1. On the shop PC, go to **https://laragon.org/download** and download **Laragon Full**.
2. Run the installer and accept the defaults. It installs to `C:\laragon`.
3. When it finishes, **don't start Laragon**, and untick **Run Laragon when Windows starts** if it asks. NunaBooks runs its own copies of MySQL and Apache. If Laragon starts them too, the two clash.

Check the folders exist. Open **File Explorer** and look in:

| Folder | What should be inside |
|---|---|
| `C:\laragon\bin\php\` | a folder whose name starts with `php-8.4` and contains **`nts`** (for example `php-8.4.19-nts-Win32-vs17-x64`) |
| `C:\laragon\bin\mysql\` | a folder whose name starts with `mysql-8.4` |
| `C:\laragon\bin\apache\` | a folder whose name starts with `httpd-2.4` |

**Write down the exact name of each of these three folders.** You'll need them in step 4.

If the PHP folder isn't version 8.4 or doesn't have `nts` in its name, ask whoever looks after the app for the right PHP 8.4 NTS build before you go on.

## Step 2. Add the Apache module NunaBooks needs (mod_fcgid)

Laragon's Apache doesn't include this module, so you add it yourself.

1. Open `C:\laragon\bin\apache\<your httpd folder>\` and look at the end of its name: it says **VS16** or **VS17**.
2. Go to **https://www.apachelounge.com/download/** and find **mod_fcgid** under the additional modules. Download the **Win64** zip that matches your VS number.
3. Open the zip and copy **`mod_fcgid.so`** into:
   `C:\laragon\bin\apache\<your httpd folder>\modules\`

## Step 3. Install Git

1. Go to **https://git-scm.com/download/win** and download Git for Windows (64-bit).
2. Run the installer and accept every default.

## Step 4. Get the NunaBooks app onto the PC

1. Click **Start**, type **PowerShell**, right-click **Windows PowerShell** and choose **Run as Administrator**. Click **Yes** when Windows asks.
2. A blue or black window opens. Type the following and press **Enter**:

   ```powershell
   git clone https://github.com/kuokoad/bookkeeper-laravel.git C:\NunaBooks\app
   ```

   The code is private, so a GitHub sign-in window opens the first time. Sign in with the GitHub account that has been given access to it, or ask whoever looks after the app to sign in for you.

   **If you were given a USB stick instead:** copy the whole app folder from it to `C:\NunaBooks\app`, hidden `.git` folder included. "Update now" needs that folder later.

3. Then type:

   ```powershell
   cd C:\NunaBooks\app
   ```

## Step 5. Run the installer

In the **same** Administrator PowerShell window, type the command below **on one line**. Replace the three folder names in angle brackets with the ones you wrote down in step 1:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install.ps1 -Firewall -PhpDir "C:\laragon\bin\php\<php folder>" -MysqlDir "C:\laragon\bin\mysql\<mysql folder>" -ApacheDir "C:\laragon\bin\apache\<httpd folder>"
```

For example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install.ps1 -Firewall -PhpDir "C:\laragon\bin\php\php-8.4.19-nts-Win32-vs17-x64" -MysqlDir "C:\laragon\bin\mysql\mysql-8.4.0-winx64" -ApacheDir "C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17"
```

- **`-Firewall`** lets a second till on the shop's network open NunaBooks. If this PC will be the only one using it, leave `-Firewall` out.
- The installer prints lines starting with `==>` as it works. It takes several minutes. **Don't close the window.**
- Near the end it prints `Restricting ... to Administrators and SYSTEM` for the app, PHP, MySQL and Apache folders. That's expected: it stops a cashier's Windows account from changing NunaBooks' files. From then on, only an administrator account can open those folders.

### Write down the MySQL password

Partway through, the installer shows:

```
  MySQL root password (shown once, write it down and keep it safe):

      xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Copy it onto paper carefully and keep it somewhere safe, away from the PC.** It's shown only this once and isn't saved anywhere. Day-to-day trading doesn't need it, but anyone repairing the database later will.

### When it finishes

The last lines say:

```
NunaBooks is installed: http://nunabooks.local/
```

### If it shows an "update key"

The first time, the installer also prints:

```
  Update now cannot download yet: GitHub does not know this PC's update key.
```

followed by a long line starting `ssh-ed25519`. That line is also saved as **NunaBooks update key.txt** on the desktop. It lets this PC download new versions of NunaBooks and nothing else. It isn't a password, so it's safe to email.

Someone who manages the NunaBooks code on GitHub adds it **once**:

1. Open **https://github.com/kuokoad/bookkeeper-laravel/settings/keys** and click **Add deploy key**.
2. Title: `shop PC`. Key: paste the whole `ssh-ed25519 ...` line.
3. Leave **Allow write access** unticked, and click **Add key**.

   (From a PC with the GitHub command line: `gh repo deploy-key add "NunaBooks update key.txt" --repo kuokoad/bookkeeper-laravel --title "shop PC"`.)

Then run the step 5 command again. This time it should say `Updates: this PC can download new versions from GitHub`, and the desktop file is removed. Running it again changes nothing else.

### The checklist

Above the last lines is a checklist from the health check (`nuna:preflight`). Two items are expected at this point: **An owner can sign in** shows as failed and **A backup has been taken** shows as a warning. Steps 6 and 7 deal with both. Anything else marked as failed: see "If something goes wrong" below.

## Step 6. Open NunaBooks and create the owner account

1. On the desktop there's now a shortcut called **NunaBooks**. Double-click it. It opens `http://nunabooks.local/` in your web browser.
2. The setup page asks for the shop's name, the owner's name, a username (letters, numbers, dots, dashes) and a password of at least 8 characters. Fill them in and save.
   - This page only works **on the shop PC itself**, not from another till.
   - Pick a strong password and write it down too. The owner is the only person who can take backups, install updates and manage staff accounts.

## Step 7. Take the first backup

In the Administrator PowerShell window (still in `C:\NunaBooks\app`), type:

```powershell
C:\laragon\bin\php\<php folder>\php.exe artisan nuna:backup
```

It should finish by saying the backup was saved and checked.

After this, NunaBooks backs itself up **every evening at 21:00** for as long as the PC is on. If the PC was off at 21:00, the backup runs the next time it starts.

## Step 8. Set up the second till (only if you used `-Firewall`)

On the other PC, open a browser and go to:

```
http://<shop PC name>/
```

To find the shop PC's name, open **Settings → System → About** on the shop PC and look at **Device name**. Both PCs must be on the same network, and the network must be set to **Private** in Windows (not Public).

## Step 9. Bring over the old shop's records (only when moving from the old app)

Whoever looks after the app usually does this step, because the figures have to be checked afterwards.

1. **Close the old app completely.**
2. Copy its database file, `bookkeeper.db`, from the old app's `data` folder to another folder, for example `C:\NunaBooks\old-copy\bookkeeper.db`. Always work from a copy, never the original.
3. In the Administrator PowerShell window, type (one command at a time):

   ```powershell
   C:\laragon\bin\php\<php folder>\php.exe artisan nuna:import-sqlite C:\NunaBooks\old-copy\bookkeeper.db
   ```
   ```powershell
   C:\laragon\bin\php\<php folder>\php.exe artisan nuna:reconcile
   ```

4. The second command compares every figure with the old app. It must end with **zero differences**. If it doesn't, **don't start trading on NunaBooks**: keep using the old app and send the output to whoever looks after the app.
5. Take another backup (step 7).

---

## Everyday use

- **Opening it:** double-click **NunaBooks** on the desktop. It starts by itself when Windows starts. There's nothing to switch on.
- **Updates:** the owner signs in, opens the **Health** page and presses **Update now**. It backs up first and puts everything back if the update fails.
- **Backups:** automatic every evening. The owner can also take one from the Health page whenever they like, or download it from there onto a USB stick. To copy the whole backups folder (`C:\NunaBooks\app\storage\backups`) instead, sign in to Windows as an administrator: other Windows accounts can't open it. When File Explorer says you don't have permission, click **Continue**.

## Restoring from a backup

Restoring replaces **everything** in NunaBooks with the backup's contents, so only do it when you really need to (for example, a damaged PC). In an Administrator PowerShell window:

```powershell
cd C:\NunaBooks\app
```
```powershell
Stop-Service NunaBooksApache
```
```powershell
C:\laragon\bin\php\<php folder>\php.exe artisan nuna:restore storage\backups\<backup file name> --force
```
```powershell
Start-Service NunaBooksApache
```

---

## If something goes wrong

| What you see | What to do |
|---|---|
| `Open PowerShell with 'Run as Administrator'` | Close the window and open PowerShell again by right-clicking it and choosing **Run as Administrator** (step 4.1). |
| `Not found: ...mod_fcgid.so` | Step 2 wasn't done, or the file went into the wrong folder. It must be in the `modules` folder of the same Apache folder you typed after `-ApacheDir`. |
| `Not found: ...php.exe` (or `mysql`, `httpd`) | One of the folder names in step 5 is mistyped. Check it against File Explorer. |
| `running scripts is disabled on this system` | You typed `.\scripts\install.ps1` by itself. Use the full command in step 5, which starts with `powershell -ExecutionPolicy Bypass`. |
| It asks for **MySQL root password** | MySQL was set up before. Type the root password you wrote down the first time. |
| `Checking the Apache configuration failed` or Apache won't start | Another program is using port 80. Usually it's Laragon or Skype: close Laragon (right-click its tray icon → Exit) and run step 5 again. |
| The shortcut opens a page that won't load | Wait a minute and try again. If it still won't load, restart the PC. Then run step 5 again: running it twice is safe and keeps your records. |
| The Health page says it **could not reach the update server** | Check the PC is online. If it is, run the step 5 command again: it checks the update key and shows the line to add on GitHub if it's missing (see "If it shows an update key"). |
| `-DeployKeyDir must have no spaces in its path` | You added `-DeployKeyDir` with a folder name containing a space. Leave it out (the default is fine), or choose a folder without spaces. |
| Anything else | Don't delete any folders. Take a photo of the PowerShell window and send it to whoever looks after the app. |

Running the installer again (step 5) is always safe. It keeps the existing records, takes a backup before changing anything, and fixes what's missing.
