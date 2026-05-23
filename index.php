<?php
// ==========================================
// 1. DATABASE CONFIGURATION & INITIALIZATION
// ==========================================
$db_file = __DIR__ . '/study_portal.db';
try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Users table (Now includes role and is_approved)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        role TEXT DEFAULT 'user',
        is_approved INTEGER DEFAULT 0
    )");
    
    // Create Friends table (Bidirectional mapping)
    $pdo->exec("CREATE TABLE IF NOT EXISTS friends (
        user_id INTEGER,
        friend_id INTEGER,
        PRIMARY KEY (user_id, friend_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(friend_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Create Chat Messages table
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_id INTEGER,
        receiver_id INTEGER,
        message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Seed default Admin Account if none exists
    $admin_check = $pdo->query("SELECT count(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($admin_check == 0) {
        $hashed = password_hash('admin123', PASSWORD_BCRYPT); // Default admin password
        $pdo->exec("INSERT INTO users (username, password, role, is_approved) VALUES ('admin', '$hashed', 'admin', 1)");
    }

} catch (PDOException $e) {
    die("Database initialization failed: " . $e->getMessage());
}

// ==========================================
// 2. SESSION & API HANDLERS
// ==========================================
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $my_id = $_SESSION['user_id'] ?? null;

    // --- AUTHENTICATION ---
    if ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($password)) { echo json_encode(['success'=>false, 'message'=>'All fields required.']); exit; }
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, is_approved) VALUES (?, ?, 'user', 0)");
            $stmt->execute([$username, $hashed_password]);
            echo json_encode(['success'=>true, 'message'=>'Account registered! Waiting for Admin approval.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false, 'message'=>'Username already taken.']);
        }
        exit;
    }
    
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_approved'] == 0) {
                echo json_encode(['success'=>false, 'message'=>'Your account is pending admin approval.']); exit;
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false, 'message'=>'Invalid credentials.']);
        }
        exit;
    }

    // --- ADMIN ACTIONS ---
    if ($action === 'get_users' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $stmt = $pdo->query("SELECT id, username, role, is_approved FROM users WHERE role != 'admin'");
        echo json_encode(['success'=>true, 'users'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }
    if ($action === 'approve_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $target_id = $_POST['target_id'] ?? 0;
        $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?")->execute([$target_id]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'delete_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $target_id = $_POST['target_id'] ?? 0;
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
        echo json_encode(['success'=>true]); exit;
    }

    // --- SOCIAL & CHAT ACTIONS ---
    if ($my_id) {
        if ($action === 'add_friend') {
            $friend_username = trim($_POST['friend_username'] ?? '');
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$friend_username, $my_id]);
            $friend = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$friend) { echo json_encode(['success'=>false, 'message'=>'User not found or invalid.']); exit; }
            try {
                // Insert both ways for simple mutual friendship
                $pdo->prepare("INSERT INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?)")
                    ->execute([$my_id, $friend['id'], $friend['id'], $my_id]);
                echo json_encode(['success'=>true]);
            } catch (PDOException $e) { echo json_encode(['success'=>false, 'message'=>'Already friends.']); }
            exit;
        }

        if ($action === 'get_friends') {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username FROM friends f 
                JOIN users u ON f.friend_id = u.id 
                WHERE f.user_id = ?
            ");
            $stmt->execute([$my_id]);
            echo json_encode(['success'=>true, 'friends'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }

        if ($action === 'send_message') {
            $receiver_id = $_POST['receiver_id'] ?? 0;
            $msg = trim($_POST['message'] ?? '');
            if($msg !== '' && $receiver_id != 0) {
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
                $stmt->execute([$my_id, $receiver_id, htmlspecialchars($msg)]);
            }
            echo json_encode(['success'=>true]); exit;
        }

        if ($action === 'get_messages') {
            $friend_id = $_POST['friend_id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT m.*, u.username as sender_name 
                FROM messages m 
                JOIN users u ON m.sender_id = u.id
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
                ORDER BY created_at ASC
            ");
            $stmt->execute([$my_id, $friend_id, $friend_id, $my_id]);
            echo json_encode(['success'=>true, 'messages'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
    }
}

// Logout
if (isset($_GET['logout'])) { session_destroy(); header("Location: ?"); exit; }

$is_logged_in = isset($_SESSION['user_id']);
$active_user = $is_logged_in ? $_SESSION['username'] : null;
$my_user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$user_role = $is_logged_in ? $_SESSION['role'] : null;
$is_admin = ($user_role === 'admin');

// Wallpaper applies if logged in, standard background otherwise
$wallpaper_url = $is_logged_in ? "fluffy-cat-rests-on-bed-with-urban-sunset-window-ai-generated-2d-cartoon-illustration-2192876.webp" : "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stylish Study Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Core Reset & Variables */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        :root { --primary-glow: #00d4ff; --player-accent: #ff2a4d; --social-accent: #10b981; --bg-dark: #0a0e17; --panel-bg: rgba(15, 23, 42, 0.65); --border-glow: rgba(0, 212, 255, 0.2); --text-light: #f1f5f9; }

        body { 
            background-color: var(--bg-dark); 
            <?php if ($is_logged_in): ?>
            background-image: url('<?php echo $wallpaper_url; ?>'); background-size: cover; background-position: center; background-attachment: fixed;
            <?php endif; ?>
            color: var(--text-light); min-height: 100vh; overflow-x: hidden; transition: background 0.8s ease;
        }
        
        #particles-js { position: fixed; width: 100%; height: 100%; top: 0; left: 0; z-index: 1; pointer-events: none; }
        .container { position: relative; z-index: 2; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }

        /* Glassmorphic Panels */
        .form-box, .dashboard-container, .admin-container {
            background: var(--panel-bg); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--border-glow); border-radius: 16px; padding: 30px;
            box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.5); width: 100%; transition: all 0.4s ease;
        }
        .form-box { max-width: 450px; }
        .dashboard-container, .admin-container { max-width: 1400px; }

        /* Panel Visibility Logic */
        .form-box { display: <?php echo !$is_logged_in ? 'block' : 'none'; ?>; }
        #register-panel { display: none; }
        .dashboard-container { display: <?php echo ($is_logged_in && !$is_admin) ? 'block' : 'none'; ?>; }
        .admin-container { display: <?php echo ($is_logged_in && $is_admin) ? 'block' : 'none'; ?>; }

        h2 { font-family: 'Cinzel', serif; font-size: 2.2rem; text-align: center; color: #fff; text-shadow: 0 0 10px var(--primary-glow); margin-bottom: 5px; }
        .subtitle { text-align: center; color: #94a3b8; font-size: 0.9rem; margin-bottom: 30px; }

        /* Inputs & Buttons */
        .input-group { position: relative; margin-bottom: 25px; }
        .input-field { width: 100%; padding: 12px 0; background: transparent; border: none; border-bottom: 2px solid #334155; color: #fff; font-size: 1rem; outline: none; }
        .input-group label { position: absolute; left: 0; top: 12px; color: #64748b; transition: 0.3s; pointer-events: none; }
        .input-field:focus ~ label, .input-field:valid ~ label { top: -12px; font-size: 0.8rem; color: var(--primary-glow); }
        .login-btn { width: 100%; padding: 14px; background: transparent; border: 2px solid var(--primary-glow); color: #fff; font-weight: 600; letter-spacing: 2px; border-radius: 8px; cursor: pointer; transition: 0.4s; }
        .login-btn:hover { background: var(--primary-glow); color: #000; box-shadow: 0 0 20px var(--primary-glow); }
        .btn-small { padding: 8px 14px; background: rgba(0,0,0,0.4); border: 1px solid var(--primary-glow); color: #fff; border-radius: 6px; cursor: pointer; transition: 0.3s; font-size: 0.85rem; text-decoration: none; }
        .btn-small:hover { background: var(--primary-glow); color: #000; }
        .widget-input { flex: 1; background: rgba(0,0,0,0.4); border: 1px solid #334155; padding: 8px 12px; color: #fff; border-radius: 6px; outline: none; }

        /* Dashboard Elements */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 25px; }
        .dash-card { background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; backdrop-filter: blur(4px); }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glow); padding-bottom: 10px; margin-bottom: 15px; }
        .widget-flex { display: flex; gap: 8px; margin-bottom: 15px; }

        /* Chat System Styles */
        .friends-bar { display: flex; gap: 10px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); overflow-x: auto; }
        .friend-chip { background: rgba(255,255,255,0.05); padding: 6px 12px; border-radius: 15px; font-size: 0.85rem; cursor: pointer; border: 1px solid transparent; transition: 0.2s; white-space: nowrap; }
        .friend-chip:hover, .friend-chip.active { border-color: var(--primary-glow); background: rgba(0, 212, 255, 0.1); }
        
        .chat-window { flex: 1; background: rgba(0,0,0,0.3); border-radius: 8px; padding: 15px; display: flex; flex-direction: column; gap: 10px; overflow-y: auto; margin-bottom: 15px; height: 250px; }
        .chat-msg { max-width: 85%; padding: 8px 12px; border-radius: 12px; font-size: 0.85rem; line-height: 1.4; position: relative; }
        .msg-sender { font-size: 0.65rem; opacity: 0.7; margin-bottom: 3px; display: block; }
        .msg-mine { background: rgba(0, 212, 255, 0.15); border: 1px solid var(--border-glow); align-self: flex-end; border-bottom-right-radius: 2px; }
        .msg-friend { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255,255,255,0.1); align-self: flex-start; border-bottom-left-radius: 2px; }
        
        /* Admin Table Styles */
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
        th { color: var(--primary-glow); }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; }
        .badge-pending { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; }
        .badge-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; }

        .signup-link { text-align: center; margin-top: 20px; font-size: 0.85rem; color: #94a3b8; }
        a { color: var(--primary-glow); text-decoration: none; }
    </style>
</head>
<body>
    <div id="particles-js"></div>
    
    <div class="container">
        <div class="form-box" id="login-panel">
            <h2>Login</h2>
            <p class="subtitle">Access Your Workspace</p>
            <form id="login-form">
                <div class="input-group">
                    <input type="text" id="login-username" required class="input-field">
                    <label>Username</label>
                </div>
                <div class="input-group">
                    <input type="password" id="login-password" required class="input-field">
                    <label>Password</label>
                </div>
                <button type="submit" class="login-btn">SIGN IN</button>
                <div class="signup-link">New student? <a href="#" id="go-to-register">Register</a></div>
            </form>
        </div>

        <div class="form-box" id="register-panel">
            <h2>Register</h2>
            <p class="subtitle">Create Account (Requires Admin Approval)</p>
            <form id="register-form">
                <div class="input-group">
                    <input type="text" id="reg-username" required class="input-field">
                    <label>Choose Username</label>
                </div>
                <div class="input-group">
                    <input type="password" id="reg-password" required class="input-field">
                    <label>Create Password</label>
                </div>
                <button type="submit" class="login-btn">CREATE ACCOUNT</button>
                <div class="signup-link">Existing user? <a href="#" id="go-to-login">Sign In</a></div>
            </form>
        </div>

        <div class="admin-container">
            <div class="card-header" style="border-bottom: 2px solid var(--border-glow); padding-bottom: 15px;">
                <div><h2 style="text-align: left; font-size: 1.8rem; margin:0;">Admin Console</h2></div>
                <a href="?logout=1" class="btn-small">Disconnect Session</a>
            </div>
            <div class="dash-card" style="margin-top: 20px;">
                <h3>User Management</h3>
                <table>
                    <thead><tr><th>ID</th><th>Username</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="admin-users-table"></tbody>
                </table>
            </div>
        </div>

        <div class="dashboard-container" id="dashboard-panel">
            <div class="card-header" style="border-bottom: 2px solid var(--border-glow); padding-bottom: 15px;">
                <div>
                    <h2 style="text-align: left; font-size: 1.8rem; margin:0;">Study Network</h2>
                    <span style="color: #94a3b8; font-size: 0.9rem;">Connected as: <?php echo htmlspecialchars($active_user ?? ''); ?></span>
                </div>
                <a href="?logout=1" class="btn-small">Disconnect Session</a>
            </div>

            <div class="dashboard-grid">
                
                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div class="dash-card">
                        <div class="card-header"><h3>Focus Timer</h3></div>
                        <h1 style="text-align:center; font-family:monospace; font-size:3.5rem; text-shadow: 0 0 15px var(--primary-glow);" id="timer-string">25:00</h1>
                        <div style="display:flex; justify-content:center; gap:10px;">
                            <button class="btn-small" id="timer-start">Start</button>
                            <button class="btn-small" id="timer-pause">Pause</button>
                            <button class="btn-small" id="timer-reset">Reset</button>
                        </div>
                    </div>
                </div>

                <div class="dash-card" style="grid-column: span 2;">
                    <div class="card-header">
                        <h3>Study Lounge Chat</h3>
                        <button class="btn-small" id="btn-add-friend" style="font-size: 0.7rem;"><i class="fas fa-user-plus"></i> Add Friend</button>
                    </div>
                    
                    <div class="friends-bar" id="friends-list">
                        <span class="friend-chip" style="opacity: 0.5;">No friends added yet</span>
                    </div>

                    <div class="chat-window" id="chat-window">
                        <div style="text-align:center; color:#64748b; margin-top: auto; margin-bottom:auto;">
                            Select a friend from the top bar to start chatting.
                        </div>
                    </div>

                    <div class="widget-flex" style="margin-bottom: 0;">
                        <input type="text" id="chat-input" class="widget-input" placeholder="Type a message..." disabled>
                        <button class="btn-small" id="btn-chat-send" disabled><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>

            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Init Particles
        document.addEventListener('DOMContentLoaded', () => {
            particlesJS('particles-js', { "particles": { "number": { "value": 60 }, "color": { "value": "#00d4ff" }, "shape": { "type": "circle" }, "opacity": { "value": 0.4 }, "size": { "value": 2 }, "line_linked": { "enable": true, "distance": 150, "color": "#00d4ff", "opacity": 0.2 }, "move": { "enable": true, "speed": 1 } } });
        });

        // Environment Variables
        const myUserId = <?php echo $my_user_id ?? 'null'; ?>;
        const myUsername = "<?php echo $active_user ?? ''; ?>";
        const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        
        // View Routing
        if(document.getElementById('go-to-register')) {
            document.getElementById('go-to-register').addEventListener('click', (e) => { e.preventDefault(); document.getElementById('login-panel').style.display = 'none'; document.getElementById('register-panel').style.display = 'block'; });
            document.getElementById('go-to-login').addEventListener('click', (e) => { e.preventDefault(); document.getElementById('register-panel').style.display = 'none'; document.getElementById('login-panel').style.display = 'block'; });
        }

        // Generic API Caller
        async function apiCall(dataObj) {
            const fd = new FormData();
            for(let key in dataObj) fd.append(key, dataObj[key]);
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            return await res.json();
        }

        // Auth Forms
        if(document.getElementById('login-form')) {
            document.getElementById('login-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiCall({ action: 'login', username: document.getElementById('login-username').value, password: document.getElementById('login-password').value });
                if(res.success) window.location.reload(); else alert(res.message);
            });
            document.getElementById('register-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiCall({ action: 'register', username: document.getElementById('reg-username').value, password: document.getElementById('reg-password').value });
                alert(res.message); if(res.success) document.getElementById('go-to-login').click();
            });
        }

        // ==========================================
        // ADMIN DASHBOARD LOGIC
        // ==========================================
        if (isAdmin) {
            async function loadUsers() {
                const res = await apiCall({action: 'get_users'});
                const tbody = document.getElementById('admin-users-table');
                tbody.innerHTML = '';
                if(res.users) {
                    res.users.forEach(u => {
                        const statusBadge = u.is_approved == 1 ? `<span class="badge badge-approved">Approved</span>` : `<span class="badge badge-pending">Pending</span>`;
                        const actionBtn = u.is_approved == 0 ? `<button class="btn-small" onclick="approveUser(${u.id})">Approve</button>` : '';
                        tbody.innerHTML += `<tr><td>${u.id}</td><td>${u.username}</td><td>${statusBadge}</td><td>${actionBtn} <button class="btn-small" style="border-color:#ef4444; color:#ef4444;" onclick="deleteUser(${u.id})">Delete</button></td></tr>`;
                    });
                }
            }
            window.approveUser = async (id) => { await apiCall({action: 'approve_user', target_id: id}); loadUsers(); }
            window.deleteUser = async (id) => { if(confirm('Delete user?')) { await apiCall({action: 'delete_user', target_id: id}); loadUsers(); } }
            loadUsers();
        }

        // ==========================================
        // USER DASHBOARD LOGIC (CHAT & TIMER)
        // ==========================================
        if (myUserId && !isAdmin) {
            
            // Focus Timer
            let countdown = null, seconds = 1500;
            const drawTime = () => document.getElementById('timer-string').innerText = `${String(Math.floor(seconds/60)).padStart(2,'0')}:${String(seconds%60).padStart(2,'0')}`;
            document.getElementById('timer-start').addEventListener('click', () => { if(!countdown) countdown = setInterval(() => { if(seconds>0) { seconds--; drawTime(); } }, 1000); });
            document.getElementById('timer-pause').addEventListener('click', () => { clearInterval(countdown); countdown=null; });
            document.getElementById('timer-reset').addEventListener('click', () => { clearInterval(countdown); countdown=null; seconds=1500; drawTime(); });

            // Chat System Variables
            let activeFriendId = null;
            let lastMessageCount = 0;
            let chatPoller = null;

            // Load Friends List
            async function loadFriends() {
                const res = await apiCall({action: 'get_friends'});
                const list = document.getElementById('friends-list');
                if(res.friends && res.friends.length > 0) {
                    list.innerHTML = '';
                    res.friends.forEach(f => {
                        list.innerHTML += `<span class="friend-chip ${f.id === activeFriendId ? 'active' : ''}" onclick="selectFriend(${f.id}, '${f.username}')"><i class="fas fa-user"></i> ${f.username}</span>`;
                    });
                }
            }

            // Add Friend
            document.getElementById('btn-add-friend').addEventListener('click', async () => {
                const fname = prompt("Enter the exact username of the friend to add:");
                if(fname) {
                    const res = await apiCall({action: 'add_friend', friend_username: fname});
                    if(res.success) { alert('Friend added!'); loadFriends(); } else { alert(res.message); }
                }
            });

            // Select Friend for Chat
            window.selectFriend = (id, name) => {
                activeFriendId = id;
                document.getElementById('chat-input').disabled = false;
                document.getElementById('btn-chat-send').disabled = false;
                loadFriends(); // Refresh to highlight active pill
                fetchMessages(); // Load immediately
                if(chatPoller) clearInterval(chatPoller);
                chatPoller = setInterval(fetchMessages, 3000); // Poll every 3 seconds
            };

            // Fetch Messages
            async function fetchMessages() {
                if(!activeFriendId) return;
                const res = await apiCall({action: 'get_messages', friend_id: activeFriendId});
                if(res.messages) {
                    if(res.messages.length !== lastMessageCount) { // Only re-render if new messages exist
                        const win = document.getElementById('chat-window');
                        win.innerHTML = '';
                        res.messages.forEach(m => {
                            const isMine = m.sender_id == myUserId;
                            win.innerHTML += `
                                <div class="chat-msg ${isMine ? 'msg-mine' : 'msg-friend'}">
                                    <span class="msg-sender">${isMine ? 'You' : m.sender_name}</span>
                                    ${m.message}
                                </div>
                            `;
                        });
                        win.scrollTop = win.scrollHeight; // Auto scroll to bottom
                        lastMessageCount = res.messages.length;
                    }
                }
            }

            // Send Message
            const sendBtn = document.getElementById('btn-chat-send');
            const chatInput = document.getElementById('chat-input');
            const sendMessage = async () => {
                const text = chatInput.value.trim();
                if(text && activeFriendId) {
                    chatInput.value = '';
                    await apiCall({action: 'send_message', receiver_id: activeFriendId, message: text});
                    fetchMessages();
                }
            };
            sendBtn.addEventListener('click', sendMessage);
            chatInput.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendMessage(); });

            // Init call
            loadFriends();
        }
    </script>
</body>
</html>