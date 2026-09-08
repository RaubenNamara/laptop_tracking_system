# Run St. Mark's Laptop Tracking on XAMPP (Windows / Mac / Linux)

A 5-minute install. You'll need [XAMPP](https://www.apachefriends.org/download.html)
already installed and the upgraded project folder
(`laptop_tracking_system_v2`) on your computer.

---

## 1. Copy the project into XAMPP's web folder

Find the **`htdocs`** folder inside your XAMPP install:

| OS      | Folder |
|---------|--------|
| Windows | `C:\xampp\htdocs` |
| macOS   | `/Applications/XAMPP/htdocs` |
| Linux   | `/opt/lampp/htdocs` |

Copy the whole `laptop_tracking_system_v2` folder there. For convenience,
**rename it** to something shorter like `laptop_tracking_system`.

You should now have:
```
C:\xampp\htdocs\laptop_tracking_system\
    assets\
    auth\
    config\
    includes\
    pages\
    ...
```

---

## 2. Start Apache and MySQL

Open the **XAMPP Control Panel** and click **Start** next to:
- **Apache**
- **MySQL**

Both should turn green. (If one fails, see the troubleshooting section
at the bottom.)

---

## 3. Create the database

1. Click the **Admin** button next to MySQL — this opens phpMyAdmin in your
   browser (`http://localhost/phpmyadmin`).
2. Click the **Import** tab at the top.
3. Click **Choose File** and select
   `htdocs/laptop_tracking_system/config/database.sql`.
4. Scroll down and click **Import**.

You should see a green success banner. The database
`laptop_tracking_system` and all its tables are now created, with a
default admin account.

---

## 4. Open the system

In your browser go to:
```
http://localhost/laptop_tracking_system/
```

Log in with:
- **Username:** `admin`
- **Password:** `admin123`

> ⚠️  **Change this password immediately** — open the **Admins** page from
> the sidebar and update it.

---

## 5. (Optional) Configure SMS

If you want parent SMS notifications and overdue alerts:

1. Sign in → click **SMS Settings** in the sidebar.
2. Pick a provider (**Twilio** or **Africa's Talking**), enter the
   credentials, and save.
3. Use **Send test message** to confirm it works.

> XAMPP must be able to reach the internet for outgoing SMS API calls.
> No extra setup is needed — `cURL` is enabled in XAMPP by default.

---

## 6. (Optional) Schedule overdue reminders

To get automatic SMS reminders for overdue laptops, schedule
`cron/check_overdue.php` to run every 30 minutes.

### Windows (Task Scheduler)
1. Press **Win + R**, type `taskschd.msc`, press Enter.
2. **Create Basic Task** → name it "Overdue Laptop Reminders".
3. Trigger: **Daily**, then on the next screen tick **Repeat task every 30
   minutes**, **for a duration of 1 day**.
4. Action: **Start a program**:
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments:
     `C:\xampp\htdocs\laptop_tracking_system\cron\check_overdue.php`

### macOS / Linux (cron)
Run `crontab -e` and add:
```
*/30 * * * * /opt/lampp/bin/php /opt/lampp/htdocs/laptop_tracking_system/cron/check_overdue.php >> /tmp/lts_overdue.log 2>&1
```

---

## Using the system from other devices on the same Wi-Fi

If you'd like teachers to scan from their phones while XAMPP runs on a
laptop:

1. Find your computer's local IP address (e.g. `192.168.1.42`).
2. On the phone (same Wi-Fi), open
   `http://192.168.1.42/laptop_tracking_system/`.
3. **Camera scanning requires HTTPS** on phones — Chrome/Safari will block
   the camera over plain `http://` from a non-localhost address. The simple
   workaround inside school: use the **Manual** tab on the Scan page and
   type or paste the laptop number. (For full camera scanning over the
   network you'd need to set up HTTPS, which is beyond XAMPP defaults.)

---

## Troubleshooting

**"Apache won't start — port 80 in use"**
Something else (Skype, IIS, another web server) is on port 80. In the
XAMPP Control Panel click **Config → Apache (httpd.conf)**, change
`Listen 80` to `Listen 8080`, save, and start Apache again. Then visit
`http://localhost:8080/laptop_tracking_system/`.

**"MySQL won't start"**
Usually a stale lock file. Open `C:\xampp\mysql\data\` and delete any file
called `ibdata1.lock`, `aria_log_control` may also need replacing — easiest
fix: in the Control Panel, click **Config → Service Settings → MySQL**
and uninstall/reinstall the service.

**"Database connection failed"**
Open `config/config.php` in a text editor. The defaults are set up for
XAMPP (user `root`, blank password). If you set a MySQL root password,
fill it in here.

**"Class 'mysqli' not found"**
Edit `C:\xampp\php\php.ini`, find the line `;extension=mysqli` and remove
the leading `;` so it reads `extension=mysqli`. Restart Apache.

**"QR scanner shows a black screen"**
Browsers require HTTPS for camera access on any address other than
`localhost`. On the same machine that's running XAMPP, use
`http://localhost/...` (works fine). On other devices, use the Manual tab.

**Want to import existing data?**
From the sidebar choose **Bulk Import** and upload CSV files for
students, parents, and laptops. Templates are linked on that page.

---

## Backups

Your school data lives in MySQL. To back up regularly:
- phpMyAdmin → select the `laptop_tracking_system` database → **Export** →
  click **Go**. Save the resulting `.sql` file somewhere safe.
- To restore: phpMyAdmin → **Import** that `.sql` file.
