<!-- Banner -->
<p align="center">
  <img src="asset/banner.png" alt="Blockchain-Secured Attendance System" width="100%">
</p>

<h1 align="center">🛡 Blockchain-Secured Log-Based Attendance System</h1>
<p align="center">
  A secure, IP-fenced, blockchain-verified attendance management system that does not use SQL — all records are stored in `.log` files and chained with blockchain-style hashing.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-blue" alt="PHP">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License">
  <img src="https://img.shields.io/badge/Storage-Log%20Files-lightgrey" alt="Log Files">
</p>

---

## 🚀 Features

<table>
<tr>
<td width="50%">

### 👤 Client/User
- Check-in / Check-out attendance  
  <img src="https://i.ibb.co/qMgy1gdx/image.png" alt="Check-in" width="100%">
  <img src="https://i.ibb.co/GSpVM9y/image.png" alt="Check-out" width="100%">
- Create support tickets  
  <img src="https://i.ibb.co/sdDxfVFt/image.png" alt="Support Tickets" width="100%">
- View announcements  
  <img src="https://i.ibb.co/mCG6hkPd/image.png" alt="View Announcements" width="100%">

</td>
<td width="50%">

### 🛠 Admin
- Enable/Disable check-in and check-out modes  
  <img src="https://i.ibb.co/QFyLBDjJ/image.png" alt="Enable/Disable Modes" width="100%">
- View all logs (valid & failed attempts)  
  <img src="https://i.ibb.co/Y736XQp5/image.png" alt="Valid Logs" width="100%">
  <img src="https://i.ibb.co/krTrJps/image.png" alt="Failed Logs" width="100%">
- Create, edit, and delete courses  
  <img src="https://i.ibb.co/zVRspk8v/image.png" alt="Manage Courses" width="100%">
- Set active courses for attendance  
  <img src="https://i.ibb.co/608T0SJ2/image.png" alt="Set Active Courses" width="100%">
- Make manual attendance entries  
  <img src="https://i.ibb.co/Ldnq0jcP/image.png" alt="Manual Attendance" width="100%">
- Unlink fingerprints from users  
  <img src="https://i.ibb.co/dsd1NVkD/image.png" alt="Unlink Fingerprints" width="100%">
- View Support Tickets  
  <img src="https://i.ibb.co/XrQGzR5R/image.png" alt="View Support Tickets" width="100%">
- Post announcements  
  <img src="https://i.ibb.co/VpP4FyKf/image.png" alt="Post Announcements" width="100%">
- Blockchain verification of logs  
  <img src="https://i.ibb.co/jkPWhBKY/image.png" alt="Blockchain Verification" width="100%">

</td>
</tr>
</table>

---

## 🔒 Security Highlights
- **Input sanitization** (`filter_var` & trimming on all POST data)  
- **IP logging** and **device fingerprinting** to prevent spoofing  
- **Duplicate prevention** (same matric or IP cannot perform the same action twice per day)  
- **Checkout restriction** — cannot checkout without a prior check-in  
- **Invalid attempts log** for all failed check-ins/outs  
- **Blockchain log chaining** to make tampering detectable  
- **Optional Polygon integration** for decentralized hash storage  

---

## ⚙️ Running Locally

### 1️ Using XAMPP or WAMP
 #  Installation
 1.  Clone the repository
 ```bash
  git clone https://github.com/MAVIS-creator/Attendance_.git
```
2. Install [XAMPP](https://www.apachefriends.org/) or [WAMP](https://www.wampserver.com/).
3. Place the project folder inside:
   - **XAMPP:** `htdocs`
   - **WAMP:** `www`
4. Start Apache from the control panel.
5. Access in your browser: 
```bash 
http://localhost/Attendance_
```

### 2️⃣ Using PHP Built-in Server
```bash
php -S localhost:8000
```
Visit:

```bash
http://localhost:8000
```
📁 Log Files
```bash

admin/logs/YYYY-MM-DD.log → valid attendance logs
admin/logs/YYYY-MM-DD_failed_attempts.log → failed login or action attempts
secure_logs/attendance_chain.json → blockchain-secured log history
```

📝 License
- This project is licensed under the MIT LICENSE – see the <a href="https://github.com/MAVIS-creator/Attendance_/blob/main/LICENSE">LICENSE</a> file for details.

👤 Author
- Mavis – Gamer, Web Developer, Security Enthusiast
- 📧 Email: mavisenquires@gmail.com
- 🐙 GitHub: [MAVIS-creator](https://github.com/MAVIS-creator)
💻  Co Author
[SamexHighshow](https://github.com/SamexHighshow)
