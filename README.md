# 🛡 Blockchain-Secured Log-Based Attendance System

A secure, IP-fenced, blockchain-verified attendance management system that **does not use SQL** — all records are stored in `.log` files and chained with blockchain-style hashing. Includes device fingerprinting, admin controls, and optional Polygon blockchain logging.

---

## ✨ Features

### 👤 Client/User
- Check-in / Check-out attendance  
  ![Check-in](https://ibb.co/CDw9Wn9)
  ![Check-out](https://ibb.co/23RsvLcx)
- Create support tickets  
  ![Support Tickets](https://ibb.co/TNrzpWj)
- View announcements  
  ![View Announcements](https://ibb.co/nMtG68SQ)

### 🛠 Admin
- Enable/Disable check-in and check-out modes  
  ![Enable/Disable Modes](https://ibb.co/TM5wnmgX)
- View all logs (valid & failed attempts)  
  ![View Logs](https://ibb.co/7x11JF2L)
  ![Failed Logs](https://ibb.co/mr5MWWqR)
- Create, edit, and delete courses  
  ![Manage Courses](https://ibb.co/jPRY0zrG)
- Set active courses for attendance  
  ![Set Active Courses](https://ibb.co/qMFVnkgP)
- Make manual attendance entries  
  ![Manual Attendance](https://ibb.co/9kmTGnWs)
- Unlink fingerprints from users  
  ![Unlink Fingerprints](https://ibb.co/WpBmCGvc)
- Post announcements  
  ![Post Announcements](https://ibb.co/wZc7B3g7)
- Blockchain verification of logs  
  ![Blockchain Verification](images/blockchain-verification.png)

---

## 🔒 Security Highlights
The system is secured with:
- **Input sanitization** (`filter_var` & trimming on all POST data)  
  ![Input Sanitization](images/input-sanitization.png)
- **IP logging** and **device fingerprinting** to prevent spoofing  
  ![IP and Device Fingerprinting](images/ip-device-fingerprinting.png)
- **Duplicate prevention** (same matric or IP cannot perform the same action twice per day)  
  ![Duplicate Prevention](images/duplicate-prevention.png)
- **Checkout restriction** — cannot checkout without a prior check-in  
  ![Checkout Restriction](images/checkout-restriction.png)
- **Invalid attempts log** for all failed check-ins/outs  
  ![Invalid Attempts Log](images/invalid-attempts.png)
- **Blockchain log chaining** to make tampering detectable  
  ![Blockchain Log Chaining](images/blockchain-chaining.png)
- **Optional Polygon integration** for decentralized hash storage  
  ![Polygon Integration](images/polygon-integration.png)

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
```bash
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









