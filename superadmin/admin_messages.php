<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('messages.manage');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();
$tableReady = ensureMessagesTable($connection);
$residents = [];

$residentsResult = $connection->query("SELECT id, full_name, username, unit_number FROM users WHERE role = 'resident' AND unit_number IS NOT NULL AND TRIM(unit_number) <> '' ORDER BY full_name ASC");
if ($residentsResult) {
    $residents = $residentsResult->fetch_all(MYSQLI_ASSOC);
}

$selectedUserId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? ($residents[0]['id'] ?? 0));
$availableResidentIds = array_map('intval', array_column($residents, 'id'));
if ($selectedUserId > 0 && !in_array($selectedUserId, $availableResidentIds, true)) {
    $selectedUserId = 0;
}
$reply = trim($_POST['reply'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        $errors[] = 'Message storage is unavailable. Please check the database setup.';
    } elseif ($selectedUserId <= 0) {
        $errors[] = 'Please select a resident.';
    }
    $attachment = storeMessageAttachment($_FILES['attachment'] ?? []);
    if ($attachment['error'] !== '') {
        $errors[] = $attachment['error'];
    }
    if ($reply === '' && empty($attachment['path'])) {
        $errors[] = 'Write a message or choose an attachment before sending.';
    } elseif (strlen($reply) > 1000) {
        $errors[] = 'Your message must be 1,000 characters or fewer.';
    }
    if (!empty($errors) && !empty($attachment['path'])) {
        @unlink(__DIR__ . '/../private_uploads/message_attachments/' . $attachment['path']);
    }
    if (empty($errors)) {
        $insert = $connection->prepare("INSERT INTO messages (user_id, sender_role, body, attachment_path, attachment_name, attachment_mime, is_read) VALUES (?, 'admin', ?, ?, ?, ?, 0)");
        $attachmentPath = $attachment['path'] ?? null;
        $attachmentName = $attachment['name'] ?? null;
        $attachmentMime = $attachment['mime'] ?? null;
        $insert->bind_param('issss', $selectedUserId, $reply, $attachmentPath, $attachmentName, $attachmentMime);
        if ($insert->execute()) {
            // Redirect to GET to prevent duplicate messages on refresh (Post-Redirect-Get pattern)
            header('Location: admin_messages.php?user_id=' . $selectedUserId);
            exit;
        } else {
            if (!empty($attachmentPath)) {
                @unlink(__DIR__ . '/../private_uploads/message_attachments/' . $attachmentPath);
            }
            $errors[] = 'Unable to send the message. Please try again.';
        }
    }
}

$selectedResident = ['full_name' => 'Select a resident', 'unit_number' => ''];
foreach ($residents as $resident) {
    if ((int)$resident['id'] === $selectedUserId) {
        $selectedResident = $resident;
        break;
    }
}

if ($tableReady && $selectedUserId > 0) {
    $markRead = $connection->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'resident'");
    $markRead->bind_param('i', $selectedUserId);
    $markRead->execute();
}

$thread = [];
if ($tableReady && $selectedUserId > 0) {
    $query = $connection->prepare('SELECT id, sender_role, body, attachment_name, attachment_mime, created_at FROM messages WHERE user_id = ? ORDER BY created_at ASC');
    $query->bind_param('i', $selectedUserId);
    $query->execute();
    $thread = $query->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css?v=<?php echo (int)filemtime(__DIR__ . '/../styles.css'); ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Messages</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit">Administrator</span>
                                </div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (!empty($errors)): ?>
                <div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <section class="unit-page-head">
                <div>
                    <h2>Resident Messages</h2>
                    <p>Chat directly with residents.</p>
                </div>
            </section>

            <section class="admin-chat-shell">
                <div class="admin-chat-residents">
                    <h2>Residents</h2>
                    <?php if (empty($residents)): ?>
                        <p class="admin-empty">No residents found.</p>
                    <?php else: ?>
                        <?php foreach ($residents as $resident): ?>
                            <a href="admin_messages.php?user_id=<?php echo (int)$resident['id']; ?>" class="admin-chat-resident <?php echo (int)$resident['id'] === $selectedUserId ? 'selected' : ''; ?>">
                                <strong><?php echo htmlspecialchars($resident['full_name']); ?></strong>
                                <small><?php echo htmlspecialchars($resident['unit_number']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="admin-chat-thread">
                    <div class="admin-chat-header">
                        <h2><?php echo htmlspecialchars($selectedResident['full_name']); ?></h2>
                        <span><?php echo htmlspecialchars($selectedResident['unit_number']); ?></span>
                    </div>
                    <div class="admin-chat-messages">
                        <?php if (empty($thread)): ?>
                            <p class="admin-empty">No messages yet. Start the conversation.</p>
                        <?php else: ?>
                            <?php foreach ($thread as $message): ?>
                                <div class="thread-message <?php echo $message['sender_role'] === 'admin' ? 'outgoing' : 'incoming'; ?>" data-message-id="<?php echo (int)$message['id']; ?>">
                                    <span class="message-author"><?php echo $message['sender_role'] === 'admin' ? 'Management Office' : htmlspecialchars($selectedResident['full_name']); ?></span>
                                    <p><?php echo nl2br(htmlspecialchars($message['body'])); ?></p>
                                    <?php if (!empty($message['attachment_name'])): ?>
                                        <?php if (strpos((string)$message['attachment_mime'], 'image/') === 0): ?>
                                            <a class="message-attachment-image" href="../message_attachment.php?id=<?php echo (int)$message['id']; ?>" data-image-src="../message_attachment.php?id=<?php echo (int)$message['id']; ?>&amp;inline=1" data-image-name="<?php echo htmlspecialchars($message['attachment_name'], ENT_QUOTES); ?>" aria-label="View image: <?php echo htmlspecialchars($message['attachment_name'], ENT_QUOTES); ?>"><img src="../message_attachment.php?id=<?php echo (int)$message['id']; ?>&amp;inline=1" alt="<?php echo htmlspecialchars($message['attachment_name']); ?>"></a>
                                        <?php endif; ?>
                                        <a class="message-attachment-link" href="../message_attachment.php?id=<?php echo (int)$message['id']; ?>" download><?php echo htmlspecialchars($message['attachment_name']); ?></a>
                                    <?php endif; ?>
                                    <time><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($message['created_at']))); ?></time>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="reply-form" action="admin_messages.php" enctype="multipart/form-data"><?php echo workflowCsrfField(); ?>
                        <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                        <label for="reply">Message to resident</label>
                        <div class="reply-controls">
                            <textarea id="reply" name="reply" rows="2" maxlength="1000" placeholder="Write a message to this resident..."><?php echo htmlspecialchars($reply); ?></textarea>
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
        /* Navigation is handled by the shared UI module. */
        /* Navigation is handled by the shared UI module. */

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

        const adminMessagesContainer = document.querySelector('.admin-chat-messages');
        function scrollAdminMessagesToLatest() {
            if (!adminMessagesContainer) return;
            adminMessagesContainer.scrollTop = adminMessagesContainer.scrollHeight;
        }
        requestAnimationFrame(scrollAdminMessagesToLatest);

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
        const selectedUserId = <?php echo $selectedUserId; ?>;
        let lastMessageCount = <?php echo count($thread); ?>;
        let isPolling = true;

        function updateAdminMessages() {
            if (!isPolling || selectedUserId <= 0) return;

            const url = '../api/admin_messages.php?user_id=' + encodeURIComponent(selectedUserId) + '&t=' + Date.now();
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

                    const messagesContainer = document.querySelector('.admin-chat-messages');
                    if (!messagesContainer) return;

                    const existingMessages = messagesContainer.querySelectorAll('[data-message-id]');
                    const existingIds = new Set(Array.from(existingMessages).map(m => parseInt(m.dataset.messageId)));

                    let hasNewMessages = false;
                    let newMessageCount = 0;
                    let lastMessageElement = null;

                    if (data.messages && Array.isArray(data.messages)) {
                        data.messages.forEach((message) => {
                            if (!existingIds.has(message.id) && message.id > 0) {
                                hasNewMessages = true;
                                newMessageCount++;
                                const messageEl = document.createElement('div');
                                messageEl.className = 'thread-message ' + (message.sender_role === 'admin' ? 'outgoing' : 'incoming');
                                messageEl.dataset.messageId = message.id;
                                const sender = message.sender_role === 'admin' ? 'Management Office' : (data.resident?.full_name || 'Resident');
                                messageEl.innerHTML = `
                                    <span class="message-author">${escapeHtml(sender)}</span>
                                    <p>${escapeHtml(message.body).replace(/\n/g, '<br>')}</p>
                                    ${renderMessageAttachment(message)}
                                    <time>${escapeHtml(message.time)}</time>
                                `;
                                messagesContainer.appendChild(messageEl);
                                lastMessageElement = messageEl;
                            }
                        });
                    }

                    // Auto-scroll to latest message if new message was added
                    if (hasNewMessages && lastMessageElement) {
                        console.log('✓ ' + newMessageCount + ' new message(s) added');
                        requestAnimationFrame(() => {
                            messagesContainer.scrollTo({ top: messagesContainer.scrollHeight, behavior: 'smooth' });
                        });
                    }

                    if (data.count !== lastMessageCount) {
                        lastMessageCount = data.count;
                    }
                })
                .catch(error => console.error('Admin message update failed:', error));
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
        console.log('🔄 Admin message live updates enabled (polling every 2s)');
        updateAdminMessages();

        // Poll often enough that incoming resident messages appear without a reload.
        setInterval(updateAdminMessages, 1500);

        // Pause polling when page is hidden, resume when visible
        document.addEventListener('visibilitychange', () => {
            isPolling = !document.hidden;
            if (isPolling) {
                console.log('📍 Page visible, resuming message polling');
                updateAdminMessages();
            } else {
                console.log('📍 Page hidden, pausing message polling');
            }
        });
    </script>
    <script src="../js/message-image-viewer.js"></script>
    <script src="../js/live-updates.js"></script>
</body>
</html>
