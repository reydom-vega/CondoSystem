<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('announcements.manage');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$errors = [];
$success = false;
$announcements = [];
$connection = connectDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireWorkflowCsrf();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $announcementId = (int)($_POST['announcement_id'] ?? 0);
    if ($announcementId > 0 && deleteAnnouncement($announcementId)) {
        logAudit('delete', 'announcement', $announcementId, 'Deleted announcement');
        $success = true;
    }
}

// Handle create/update
$notifySummary = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $announcementId = (int)($_POST['announcement_id'] ?? 0);
    $shouldNotify = isset($_POST['notify_residents']);
    $expiresAtInput = trim($_POST['expires_at'] ?? '');
    $expiresAt = null;
    if ($expiresAtInput !== '') {
        // datetime-local sends "YYYY-MM-DDTHH:MM"; MySQL wants a space instead of "T".
        $expiresAt = str_replace('T', ' ', $expiresAtInput) . ':00';
    }

    if ($title === '' || $content === '') {
        $errors[] = 'Title and content are required.';
    } elseif (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $errors[] = 'Invalid priority level.';
    } elseif ($expiresAt !== null && strtotime($expiresAt) <= time()) {
        $errors[] = 'Expiry date must be in the future.';
    } else {
        if ($announcementId > 0) {
            if (updateAnnouncement($announcementId, $title, $content, $category, $priority, 1, $expiresAt)) {
                logAudit('update', 'announcement', $announcementId, 'Updated announcement: ' . $title);
                $success = true;
                if ($shouldNotify) {
                    $notifyResult = notifyResidentsOfAnnouncement($announcementId);
                    $notifySummary = "Queued {$notifyResult['emails_queued']} email notice(s) and {$notifyResult['sms_queued']} SMS notice(s). Delivery runs in the notification worker.";
                }
            } else {
                $errors[] = 'Failed to update announcement.';
            }
        } else {
            $newAnnouncementId = createAnnouncement($title, $content, $category, $priority, $expiresAt);
            if ($newAnnouncementId !== false) {
                logAudit('create', 'announcement', $newAnnouncementId, 'Created announcement: ' . $title);
                $success = true;
                if ($shouldNotify) {
                    $notifyResult = notifyResidentsOfAnnouncement($newAnnouncementId);
                    $notifySummary = "Queued {$notifyResult['emails_queued']} email notice(s) and {$notifyResult['sms_queued']} SMS notice(s). Delivery runs in the notification worker.";
                }
            } else {
                $errors[] = 'Failed to create announcement.';
            }
        }
    }
}

// Get announcements
$announcements = getAnnouncements(50, false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
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
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Announcements</h1>
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
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="action-row">
                <button onclick="openCreateModal()" class="btn-primary" style="background: #d97706;"><?php echo systemIcon('announcements', 'system-action-icon'); ?> New Announcement</button>
                <button onclick="showTemplateModal()" class="btn-primary" style="background: #0891b2;"><?php echo systemIcon('bolt', 'system-action-icon'); ?> Quick Template</button>
            </div>

            <?php if ($success): ?>
                <div class="alert success" style="margin-bottom: 20px;">Announcement saved successfully! <?php echo htmlspecialchars($notifySummary); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert error" style="margin-bottom: 20px;">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="admin-panel">
                <h2>All Announcements</h2>
                <?php if (empty($announcements)): ?>
                    <p class="admin-empty">No announcements yet. Create one to get started!</p>
                <?php else: ?>
                    <div>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-item">
                                <div class="announcement-info">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                                        <strong class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                        <span class="priority-badge priority-<?php echo $announcement['priority']; ?>"><?php echo strtoupper($announcement['priority']); ?></span>
                                        <?php if (!empty($announcement['expires_at'])):
                                            $isExpired = strtotime($announcement['expires_at']) <= time();
                                        ?>
                                            <span class="priority-badge" style="background: <?php echo $isExpired ? '#4b5563' : '#0891b2'; ?>;">
                                                <?php echo $isExpired ? 'EXPIRED' : 'EXPIRES ' . strtoupper(date('M d, H:i', strtotime($announcement['expires_at']))); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="announcement-meta">
                                        <?php if (!empty($announcement['category'])): ?>
                                            <strong><?php echo htmlspecialchars($announcement['category']); ?></strong> •
                                        <?php endif; ?>
                                        <?php echo date('M d, Y H:i', strtotime($announcement['created_at'])); ?>
                                    </div>
                                    <div class="announcement-content"><?php echo htmlspecialchars(substr($announcement['content'], 0, 150)); ?><?php echo strlen($announcement['content']) > 150 ? '...' : ''; ?></div>
                                </div>
                                <div class="announcement-actions">
                                    <button class="btn-small btn-edit" onclick="editAnnouncement(<?php echo $announcement['id']; ?>, <?php echo htmlspecialchars(json_encode($announcement), ENT_QUOTES); ?>)">Edit</button>
                                    <form method="post" style="display: inline;" data-confirm="Delete this announcement? This action cannot be undone." data-confirm-title="Delete announcement" data-confirm-action="Delete"><?php echo workflowCsrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                        <button type="submit" class="btn-small btn-delete">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="announcementModal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle">New Announcement</span>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="post" style="margin-top: 20px;"><?php echo workflowCsrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="announcement_id" id="announcement_id" value="0">

                <div style="margin-bottom: 15px;">
                    <label for="title" style="display: block; margin-bottom: 5px; color: #cbd5e1;">Title</label>
                    <input type="text" id="title" name="title" required style="width: 100%; padding: 10px; background: #1f293d; border: 1px solid #374151; border-radius: 6px; color: #fff;" placeholder="Announcement title">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="category" style="display: block; margin-bottom: 5px; color: #cbd5e1;">Category</label>
                    <input type="text" id="category" name="category" style="width: 100%; padding: 10px; background: #1f293d; border: 1px solid #374151; border-radius: 6px; color: #fff;" placeholder="e.g., Maintenance, Events, Security">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="priority" style="display: block; margin-bottom: 5px; color: #cbd5e1;">Priority</label>
                    <select id="priority" name="priority" style="width: 100%; padding: 10px; background: #1f293d; border: 1px solid #374151; border-radius: 6px; color: #fff;">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="expires_at" style="display: block; margin-bottom: 5px; color: #cbd5e1;">Expires On <span style="color: #6b7280; font-weight: normal;">(optional)</span></label>
                    <input type="datetime-local" id="expires_at" name="expires_at" style="width: 100%; padding: 10px; background: #1f293d; border: 1px solid #374151; border-radius: 6px; color: #fff;">
                    <small style="color: #6b7280;">Leave blank for a permanent announcement. After this date/time, it disappears from resident dashboards automatically.</small>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="content" style="display: block; margin-bottom: 5px; color: #cbd5e1;">Content</label>
                    <textarea id="content" name="content" required style="width: 100%; padding: 10px; background: #1f293d; border: 1px solid #374151; border-radius: 6px; color: #fff; min-height: 150px; font-family: inherit;" placeholder="Write your announcement here..."></textarea>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; color: #cbd5e1; cursor: pointer;">
                        <input type="checkbox" id="notify_residents" name="notify_residents" checked style="width: auto;">
                        Email and text this to all residents
                    </label>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary" style="flex: 1; padding: 10px; background: #d97706; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Save Announcement</button>
                    <button type="button" onclick="closeModal()" style="flex: 1; padding: 10px; background: #374151; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="templateModal">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <span>Select Announcement Template</span>
                <button class="modal-close" onclick="closeTemplateModal()">&times;</button>
            </div>
            <div style="margin-top: 20px;">
                <div class="template-grid">
                    <button type="button" class="template-btn" onclick="useTemplate('water')">
                        <?php echo systemIconFromGlyph('🚨', 'template-icon'); ?>
                        <div class="template-name">Water Service</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('power')">
                        <?php echo systemIconFromGlyph('⚡', 'template-icon'); ?>
                        <div class="template-name">Power Maintenance</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('pest')">
                        <?php echo systemIconFromGlyph('🦠', 'template-icon'); ?>
                        <div class="template-name">Pest Control</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('meeting')">
                        <?php echo systemIconFromGlyph('📋', 'template-icon'); ?>
                        <div class="template-name">Meeting Notice</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('security')">
                        <?php echo systemIconFromGlyph('🔒', 'template-icon'); ?>
                        <div class="template-name">Security Alert</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('maintenance')">
                        <?php echo systemIconFromGlyph('🛠️', 'template-icon'); ?>
                        <div class="template-name">Maintenance Work</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('event')">
                        <?php echo systemIconFromGlyph('🎉', 'template-icon'); ?>
                        <div class="template-name">Event Notice</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('reminder')">
                        <?php echo systemIconFromGlyph('📌', 'template-icon'); ?>
                        <div class="template-name">Reminder</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('payment')">
                        <?php echo systemIconFromGlyph('💳', 'template-icon'); ?>
                        <div class="template-name">Payment Due</div>
                    </button>
                    <button type="button" class="template-btn" onclick="useTemplate('rule')">
                        <?php echo systemIconFromGlyph('📜', 'template-icon'); ?>
                        <div class="template-name">Community Rules</div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('announcement_id').value = 0;
            document.getElementById('title').value = '';
            document.getElementById('category').value = '';
            document.getElementById('priority').value = 'medium';
            document.getElementById('content').value = '';
            document.getElementById('expires_at').value = '';
            document.getElementById('modalTitle').textContent = 'New Announcement';
            document.getElementById('announcementModal').classList.add('open');
        }

        function editAnnouncement(id, announcement) {
            document.getElementById('announcement_id').value = id;
            document.getElementById('title').value = announcement.title;
            document.getElementById('category').value = announcement.category || '';
            document.getElementById('priority').value = announcement.priority;
            document.getElementById('content').value = announcement.content;
            // MySQL gives "YYYY-MM-DD HH:MM:SS"; the datetime-local input needs "YYYY-MM-DDTHH:MM".
            document.getElementById('expires_at').value = announcement.expires_at
                ? announcement.expires_at.replace(' ', 'T').substring(0, 16)
                : '';
            document.getElementById('modalTitle').textContent = 'Edit Announcement';
            document.getElementById('announcementModal').classList.add('open');
        }

        function closeModal() {
            document.getElementById('announcementModal').classList.remove('open');
        }

        document.getElementById('announcementModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

            // Announcement templates
            const templates = {
                water: {
                    title: '🚨 Water Service Interruption',
                    category: 'Utilities',
                    priority: 'high',
                    content: 'Scheduled water service interruption on [DATE] from [TIME]. We apologize for any inconvenience. Please store sufficient water beforehand. For concerns, contact the management office.'
                },
                power: {
                    title: '⚡ Power Maintenance Notice',
                    category: 'Utilities',
                    priority: 'high',
                    content: 'Electrical maintenance scheduled on [DATE] from [TIME] to [TIME]. All units may experience power outages. Please prepare accordingly and avoid using heavy appliances during this period.'
                },
                pest: {
                    title: '🦠 Pest Control Operations',
                    category: 'Maintenance',
                    priority: 'medium',
                    content: 'Pest control treatment will be conducted on [DATE]. Please keep your unit locked and windows closed for 2-3 hours after treatment. Remove pets from the area. Ensure good ventilation after the treatment period.'
                },
                meeting: {
                    title: '📋 Homeowners Meeting Scheduled',
                    category: 'Events',
                    priority: 'medium',
                    content: 'Annual Homeowners Meeting on [DATE] at [TIME] in the function hall. Attendance is encouraged. Topics: [LIST TOPICS]. Please RSVP to the management office by [DEADLINE].'
                },
                security: {
                    title: '🔒 Security Update',
                    category: 'Security',
                    priority: 'medium',
                    content: 'Enhanced security measures are being implemented in the community. Please display your access card at all times. Report any suspicious activities to the security office immediately at [PHONE].'
                },
                maintenance: {
                    title: '🛠️ Building Maintenance Alert',
                    category: 'Maintenance',
                    priority: 'medium',
                    content: 'Routine maintenance of common areas will be performed on [DATE]. Access to [AREA] may be restricted during [TIME]. We appreciate your patience and cooperation.'
                },
                event: {
                    title: '🎉 Community Event Announcement',
                    category: 'Events',
                    priority: 'low',
                    content: 'You are cordially invited to [EVENT NAME] on [DATE] at [TIME] in the [LOCATION]. Light refreshments will be served. RSVP to [CONTACT] by [DATE]. All residents welcome!'
                },
                reminder: {
                    title: '📌 Important Reminder',
                    category: 'General',
                    priority: 'low',
                    content: 'This is a reminder to [ACTION]. As per the community guidelines, all residents are required to [REQUIREMENT]. For more information, please visit the management office or call [PHONE].'
                },
                payment: {
                    title: '💳 Payment Deadline Notice',
                    category: 'Payments',
                    priority: 'high',
                    content: 'The monthly dues payment deadline is [DATE]. Please settle your account to avoid late charges. You can pay via [PAYMENT METHODS]. For assistance, contact the accounting office at [PHONE].'
                },
                rule: {
                    title: '📜 Community Rules Enforcement',
                    category: 'Rules',
                    priority: 'medium',
                    content: 'We remind all residents to comply with community rules regarding [RULE TOPIC]. Violations may result in fines or other disciplinary actions. For clarification, refer to the Community Guidelines posted in the main lobby or contact management.'
                }
            };

            function showTemplateModal() {
                const modal = document.getElementById('templateModal');
                if (modal) modal.classList.add('open');
            }

            function useTemplate(templateKey) {
                const template = templates[templateKey];
                if (!template) return;

                document.getElementById('announcement_id').value = 0;
                document.getElementById('title').value = template.title;
                document.getElementById('category').value = template.category;
                document.getElementById('priority').value = template.priority;
                document.getElementById('content').value = template.content;
                document.getElementById('expires_at').value = '';
                document.getElementById('modalTitle').textContent = 'New Announcement (from Template)';

                closeTemplateModal();
                document.getElementById('announcementModal').classList.add('open');
            }

            function closeTemplateModal() {
                const modal = document.getElementById('templateModal');
                if (modal) modal.classList.remove('open');
            }
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        /* Navigation is handled by the shared UI module. */
        /* Navigation is handled by the shared UI module. */

        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });

        document.getElementById('templateModal').addEventListener('click', function(e) {
            if (e.target === this) closeTemplateModal();
        });
    </script>

</body>
</html>
