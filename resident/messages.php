<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();
requireResidentPermission('resident.messages.use');

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$connection = connectDb();
ensureMessagesTable($connection);
$announcements = getAnnouncements(50, true);
$markRead = $connection->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'admin'");
$markRead->bind_param('i', $_SESSION['user_id']);
$markRead->execute();
$selectedId = 'office';
$reply = trim($_POST['reply'] ?? '');
$errors = [];
$search = trim($_GET['search'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $attachment = storeMessageAttachment($_FILES['attachment'] ?? []);
    if ($attachment['error'] !== '') {
        $errors[] = $attachment['error'];
    }
    if ($reply === '' && empty($attachment['path'])) {
        $errors[] = 'Write a reply or choose an attachment before sending.';
    } elseif (strlen($reply) > 1000) {
        $errors[] = 'Your reply must be 1,000 characters or fewer.';
    }
    if (!empty($errors) && !empty($attachment['path'])) {
        @unlink(__DIR__ . '/../private_uploads/message_attachments/' . $attachment['path']);
    }
    if (empty($errors)) {
        $insert = $connection->prepare("INSERT INTO messages (user_id, sender_role, body, attachment_path, attachment_name, attachment_mime, is_read) VALUES (?, 'resident', ?, ?, ?, ?, 0)");
        $attachmentPath = $attachment['path'] ?? null;
        $attachmentName = $attachment['name'] ?? null;
        $attachmentMime = $attachment['mime'] ?? null;
        $insert->bind_param('issss', $_SESSION['user_id'], $reply, $attachmentPath, $attachmentName, $attachmentMime);
        if ($insert->execute()) {
            // Redirect to GET to prevent duplicate messages on refresh (Post-Redirect-Get pattern)
            header('Location: messages.php');
            exit;
        } else {
            if (!empty($attachmentPath)) {
                @unlink(__DIR__ . '/../private_uploads/message_attachments/' . $attachmentPath);
            }
            $errors[] = 'Failed to send message. Please try again.';
        }
    }
}

$selectedConversation = ['id' => 'office', 'title' => 'Management Office', 'messages' => []];
$messageQuery = $connection->prepare('SELECT id, sender_role, body, attachment_name, attachment_mime, created_at, is_read FROM messages WHERE user_id = ? ORDER BY created_at ASC');
$messageQuery->bind_param('i', $_SESSION['user_id']);
$messageQuery->execute();
foreach ($messageQuery->get_result() as $message) {
    $selectedConversation['messages'][] = ['id' => (int)$message['id'], 'author' => $message['sender_role'] === 'admin' ? 'Management Office' : $username, 'body' => $message['body'], 'attachment_name' => $message['attachment_name'], 'attachment_mime' => $message['attachment_mime'], 'time' => date('M j, Y g:i A', strtotime($message['created_at'])), 'incoming' => $message['sender_role'] === 'admin'];
}
if (empty($selectedConversation['messages'])) {
    $selectedConversation['messages'][] = ['id' => 0, 'author' => 'Management Office', 'body' => 'Welcome to the Celandine Residences messaging center. Send us a message and our team will respond here.', 'time' => 'Today', 'incoming' => true];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Messages</title>
    <link rel="stylesheet" href="../resident.css?v=<?php echo (int)filemtime(__DIR__ . '/../resident.css'); ?>">
</head>
<body class="dashboard-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Messages</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span>
                                </div>
                            </div>
                            <a href="edit_profile.php" class="profile-dropdown-item"><?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile</a>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (!empty($errors)): ?>
                <div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <section class="messages-shell" aria-label="Messages center">
                <div class="conversation-list">
                    <div class="messages-list-header">
                        <h2 class="section-title">Messages</h2>
                        <span class="message-count"><?php echo count($selectedConversation['messages']); ?></span>
                    </div>
                    <form class="message-search" method="get" action="messages.php">
                        <label for="messageSearch">Search messages</label>
                        <input id="messageSearch" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search messages...">
                    </form>
                    <div class="conversation-items"><div class="conversation-item selected"><span class="conversation-avatar">M</span><span class="conversation-copy"><strong>Management Office</strong><span>Resident support conversation</span></span></div></div>
                </div>

                <div class="message-thread">
                    <div class="thread-header">
                        <div>
                            <span class="thread-eyebrow">Conversation</span>
                            <h2><?php echo htmlspecialchars($selectedConversation['title']); ?></h2>
                        </div>
                        <span class="thread-status">Active</span>
                    </div>
                    <?php if (!empty($announcements)): ?>
                        <section class="message-announcements" id="messageAnnouncements" aria-label="Announcements from management">
                            <h3 class="message-announcements-title"><?php echo systemIcon('announcements', 'message-heading-icon'); ?> Announcements from Management</h3>
                            <?php foreach ($announcements as $announcement):
                                $annKey = htmlspecialchars((string)($announcement['id'] ?? md5($announcement['title'] . $announcement['created_at'])));
                            ?>
                                <article class="message-announcement <?php echo $announcement['priority'] === 'high' ? 'high' : ''; ?>" data-announcement-key="<?php echo $annKey; ?>">
                                    <div class="message-announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                    <div class="message-announcement-meta">
                                        <?php echo htmlspecialchars($announcement['category'] ?: 'General'); ?>
                                        · <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($announcement['created_at']))); ?>
                                        · <?php echo strtoupper(htmlspecialchars($announcement['priority'])); ?> priority
                                    </div>
                                    <div class="message-announcement-content"><?php echo htmlspecialchars($announcement['content']); ?></div>
                                    <div class="message-announcement-note">This is an announcement from management. Replies are not available.</div>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                    <script>
                        // Hide announcements the resident has already seen, so the chat stays in view.
                        (function () {
                            var STORAGE_KEY = 'celandine_seen_announcements';
                            var section = document.getElementById('messageAnnouncements');
                            if (!section) return;

                            var seen = [];
                            try {
                                seen = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
                            } catch (e) {
                                seen = [];
                            }

                            var articles = section.querySelectorAll('.message-announcement[data-announcement-key]');
                            var stillUnseenKeys = [];

                            articles.forEach(function (article) {
                                var key = article.getAttribute('data-announcement-key');
                                if (seen.indexOf(key) !== -1) {
                                    article.remove();
                                } else {
                                    stillUnseenKeys.push(key);
                                }
                            });

                            // Nothing left to show (all previously seen) — collapse the whole section.
                            if (!section.querySelector('.message-announcement')) {
                                section.style.display = 'none';
                            }

                            // Now that the resident is viewing these, mark them as seen for next time.
                            if (stillUnseenKeys.length > 0) {
                                var updated = seen.concat(stillUnseenKeys);
                                try {
                                    localStorage.setItem(STORAGE_KEY, JSON.stringify(updated));
                                } catch (e) {
                                    // localStorage unavailable (private browsing, etc.) — fail silently.
                                }
                            }
                        })();
                    </script>
                    <div class="thread-messages">
                        <?php foreach ($selectedConversation['messages'] as $message): ?>
                            <div class="thread-message <?php echo $message['incoming'] ? 'incoming' : 'outgoing'; ?>" data-message-id="<?php echo $message['id']; ?>">
                                <span class="message-author"><?php echo htmlspecialchars($message['author']); ?></span>
                                <p><?php echo nl2br(htmlspecialchars($message['body'])); ?></p>
                                <?php if (!empty($message['attachment_name'])): ?>
                                    <?php if (strpos((string)$message['attachment_mime'], 'image/') === 0): ?>
                                        <a class="message-attachment-image" href="../message_attachment.php?id=<?php echo (int)$message['id']; ?>" data-image-src="../message_attachment.php?id=<?php echo (int)$message['id']; ?>&amp;inline=1" data-image-name="<?php echo htmlspecialchars($message['attachment_name'], ENT_QUOTES); ?>" aria-label="View image: <?php echo htmlspecialchars($message['attachment_name'], ENT_QUOTES); ?>"><img src="../message_attachment.php?id=<?php echo (int)$message['id']; ?>&amp;inline=1" alt="<?php echo htmlspecialchars($message['attachment_name']); ?>"></a>
                                    <?php endif; ?>
                                    <a class="message-attachment-link" href="../message_attachment.php?id=<?php echo (int)$message['id']; ?>" download><?php echo htmlspecialchars($message['attachment_name']); ?></a>
                                <?php endif; ?>
                                <time><?php echo htmlspecialchars($message['time']); ?></time>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form class="reply-form" method="post" action="messages.php" enctype="multipart/form-data">
                        <?php echo workflowCsrfField(); ?>
                        <label for="reply">Reply to <?php echo htmlspecialchars($selectedConversation['title']); ?></label>
                        <div class="reply-controls">
                            <textarea id="reply" name="reply" rows="1" maxlength="1000" placeholder="Type your reply..." aria-label="Type your reply"><?php echo htmlspecialchars($reply); ?></textarea>
                            <label class="message-attachment-button" for="attachment"><?php echo systemIcon('paperclip', 'message-control-icon'); ?><span class="message-attachment-name">Attach file</span></label>
                            <input class="message-attachment-input" type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx">
                            <button type="submit" class="reply-send">Send <?php echo systemIcon('send', 'message-control-icon'); ?></button>
                        </div>
                        <small class="message-attachment-help">Images and documents, up to 10 MB.</small>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });

        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', isOpen);
        });
        document.addEventListener('click', (event) => {
            if (!profileMenu.contains(event.target)) {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });

        const residentMessagesContainer = document.querySelector('.thread-messages');
        if (residentMessagesContainer) {
            requestAnimationFrame(() => {
                residentMessagesContainer.scrollTop = residentMessagesContainer.scrollHeight;
            });
        }

        const attachmentInput = document.getElementById('attachment');
        if (attachmentInput) {
            attachmentInput.addEventListener('change', () => {
                const attachmentLabel = document.querySelector('label[for="attachment"]');
                const attachmentName = attachmentLabel.querySelector('.message-attachment-name');
                attachmentName.textContent = attachmentInput.files.length ? attachmentInput.files[0].name : 'Attach file';
                attachmentLabel.title = attachmentName.textContent;
            });
        }

        // === LIVE MESSAGE UPDATES ===
        let lastMessageCount = <?php echo count($selectedConversation['messages']); ?>;
        let lastAnnouncementCount = <?php echo count($announcements); ?>;
        let isPolling = true;

        function formatMessageTime(timestamp) {
            const date = new Date(timestamp * 1000);
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);

            if (date.toDateString() === today.toDateString()) {
                return 'Today ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            } else if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            } else {
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined }) + ' ' + 
                       date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            }
        }

        function updateMessages() {
            if (!isPolling) return;

            const url = '../api/messages.php?t=' + Date.now();
            
            fetch(url)
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        console.error('API returned error:', data);
                        return;
                    }

                    const messagesContainer = document.querySelector('.thread-messages');
                    if (!messagesContainer) return;

                    const messageCountEl = document.querySelector('.message-count');
                    const announcementSection = document.querySelector('.message-announcements');

                    // Update message count
                    if (data.count !== lastMessageCount) {
                        if (messageCountEl) {
                            messageCountEl.textContent = data.count;
                        }
                        lastMessageCount = data.count;
                    }

                    // Check for new messages
                    const existingMessages = messagesContainer.querySelectorAll('[data-message-id]');
                    const existingIds = new Set(Array.from(existingMessages).map(m => parseInt(m.dataset.messageId)));
                    
                    let hasNewMessages = false;
                    let newMessageCount = 0;
                    
                    if (data.messages && Array.isArray(data.messages)) {
                        data.messages.forEach((message) => {
                            if (!existingIds.has(message.id) && message.id > 0) {
                                hasNewMessages = true;
                                newMessageCount++;
                                const messageEl = document.createElement('div');
                                messageEl.className = 'thread-message ' + (message.incoming ? 'incoming' : 'outgoing');
                                messageEl.dataset.messageId = message.id;
                                messageEl.innerHTML = `
                                    <span class="message-author">${escapeHtml(message.author)}</span>
                                    <p>${escapeHtml(message.body).replace(/\n/g, '<br>')}</p>
                                    ${renderMessageAttachment(message)}
                                    <time>${escapeHtml(message.time)}</time>
                                `;
                                messagesContainer.appendChild(messageEl);
                            }
                        });
                    }

                    // Auto-scroll to latest message if new message was added
                    if (hasNewMessages) {
                        console.log('✓ ' + newMessageCount + ' new message(s) added');
                        requestAnimationFrame(() => {
                            messagesContainer.scrollTo({ top: messagesContainer.scrollHeight, behavior: 'smooth' });
                        });
                    }

                    // Update announcements if new ones
                    if (data.announcements && data.announcements.length !== lastAnnouncementCount) {
                        lastAnnouncementCount = data.announcements.length;
                    }
                })
                .catch(error => console.error('Message update failed:', error));
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function renderMessageAttachment(message) {
            if (!message.attachment_name) return '';
            const downloadUrl = '../message_attachment.php?id=' + encodeURIComponent(message.id);
            const image = message.attachment_mime && message.attachment_mime.indexOf('image/') === 0
                ? '<a class="message-attachment-image" href="' + downloadUrl + '" data-image-src="' + downloadUrl + '&inline=1" data-image-name="' + escapeHtml(message.attachment_name) + '" aria-label="View image: ' + escapeHtml(message.attachment_name) + '"><img src="' + downloadUrl + '&inline=1" alt="' + escapeHtml(message.attachment_name) + '"></a>'
                : '';
            return image + '<a class="message-attachment-link" href="' + downloadUrl + '" download>' + escapeHtml(message.attachment_name) + '</a>';
        }

        // Initial update
        console.log('🔄 Message live updates enabled (polling every 2s)');
        updateMessages();

        // Poll for new messages every 2 seconds
        setInterval(updateMessages, 2000);

        // Pause polling when page is hidden, resume when visible
        document.addEventListener('visibilitychange', () => {
            isPolling = !document.hidden;
            if (isPolling) {
                console.log('📍 Page visible, resuming message polling');
                updateMessages();
            } else {
                console.log('📍 Page hidden, pausing message polling');
            }
        });
    </script>
    <script src="../js/message-image-viewer.js"></script>
    <script src="../js/live-updates.js"></script>
</body>
</html> w
