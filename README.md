# 🛡 Blockchain-Secured Log-Based Attendance System

A secure, IP-fenced, blockchain-verified attendance management system that **does not use SQL** — all records are stored in `.log` files and chained with blockchain-style hashing. Includes device fingerprinting, admin controls, and optional Polygon blockchain logging.

---

## ✨ Features

### 👤 Client/User
- Check-in / Check-out attendance  
  ![Check-in](https://i.ibb.co/qMgy1gdx/image.png)
  ![Check-out](https://i.ibb.co/GSpVM9y/image.png)
- Create support tickets  
  ![Support Tickets](https://i.ibb.co/sdDxfVFt/image.png)
- View announcements  
  ![View Announcements](https://i.ibb.co/mCG6hkPd/image.png)

### 🛠 Admin
- Enable/Disable check-in and check-out modes  
  ![Enable/Disable Modes](https://i.ibb.co/QFyLBDjJ/image.png)
- View all logs (valid & failed attempts)  
  ![View Logs](https://i.ibb.co/Y736XQp5/image.png)
  ![Failed Logs](https://i.ibb.co/krTrJps/image.png)
- Create, edit, and delete courses  
  ![Manage Courses](https://i.ibb.co/zVRspk8v/image.png)
- Set active courses for attendance  
  ![Set Active Courses](https://i.ibb.co/608T0SJ2/image.png)
- Make manual attendance entries  
  ![Manual Attendance](https://i.ibb.co/Ldnq0jcP/image.png)
- Unlink fingerprints from users  
  ![Unlink Fingerprints](https://ibb.co/WpBmCGvc)
- View Support Tickets
  ![View Support Tickets](https://i.ibb.co/XrQGzR5R/image.png)
- Post announcements  
  ![Post Announcements](https://i.ibb.co/VpP4FyKf/image.png)
- Blockchain verification of logs  
  ![Blockchain Verification](https://i.ibb.co/jkPWhBKY/image.png)

---

## 🔒 Security Highlights
The system is secured with:
- **Input sanitization** (`filter_var` & trimming on all POST data) 
- **IP logging** and **device fingerprinting** to prevent spoofing  
- **Duplicate prevention** (same matric or IP cannot perform the same action twice per day)  
- **Checkout restriction** — cannot checkout without a prior check-in  
- **Invalid attempts log** for all failed check-ins/outs  
- **Blockchain log chaining** to make tampering detectable  
- **Optional Polygon integration** for decentralized hash storage  

---

## ⚙️ Running Locally

### 1️⃣ Using XAMPP or WAMP
1. Install [XAMPP](https://www.apachefriends.org/) or [WAMP](https://www.wampserver.com/).
2. Place the project folder inside:
   - **XAMPP:** `htdocs`
   - **WAMP:** `www`
3. Start Apache from the control panel.
4. Access in your browser:  
http://localhost/your-folder-name



---

### 2️⃣ Using PHP Built-in Server
1. Open a terminal in the project folder.
2. Run:
php -S localhost:8000
Visit:


http://localhost:8000
📁 Log Files
admin/logs/YYYY-MM-DD.log → valid attendance logs

admin/logs/YYYY-MM-DD_failed_attempts.log → failed login or action attempts

secure_logs/attendance_chain.json → blockchain-secured log history

📝 License
MIT License – Use and modify freely.

👤 Author
Mavis – Gamer, Web Developer, Security Enthusiast
📧 Email: mavisenquires@gmail.com
🐙 GitHub: MAVIS-creator



If you name your screenshots exactly like the placeholders above and put them inside an `images/` folder in your repo root, GitHub will render them perfectly.  

Do you want me to also **map these image names directly to the screenshots from your actual project UI** so you don’t have to rename them manually? That would make the README fully plug-and-play.









