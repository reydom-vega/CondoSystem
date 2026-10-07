<?php

function systemSidebarIcon(string $name): string {
    $iconPaths = [
        'dashboard' => '<path d="m3 10 9-7 9 7"/><path d="M5 9v12h14V9"/><path d="M9 21v-7h6v7"/>',
        'units' => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
        'residents' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
        'pending' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'staff' => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="9" r="2.5"/><path d="M8 16c.8-1.4 2.1-2 4-2s3.2.6 4 2"/>',
        'billing' => '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M7 15h4"/><path d="M7 5V3h12v2"/>',
        'scanner' => '<path d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4"/><path d="M8 9h2v2H8zM14 9h2v2h-2zM8 14h2v2H8zM14 14h2v2h-2zM18 12v5"/>',
        'bills' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>',
        'violations' => '<path d="m12 3 10 18H2L12 3z"/><path d="M12 9v5M12 18h.01"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/>',
        'maintenance' => '<path d="M14.5 6.5a5 5 0 0 0-6.8 6.8L3 18l3 3 4.7-4.7a5 5 0 0 0 6.8-6.8L14 13l-3-3z"/>',
        'messages' => '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
        'announcements' => '<path d="m3 11 18-5v12L3 13z"/><path d="M11 15v5M7 14l1 5M21 10h1M21 14h1"/>',
        'analytics' => '<path d="M4 19V5M4 19h17"/><rect x="7" y="11" width="3" height="5" rx=".5"/><rect x="13" y="8" width="3" height="8" rx=".5"/><rect x="19" y="4" width="3" height="12" rx=".5"/>',
        'parking' => '<path d="M5 21V3h8a6 6 0 0 1 0 12H5"/><path d="M9 7v4h4a2 2 0 1 0 0-4z"/>',
        'audit' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5V3h6v1.5M9 10h6M9 14h6M9 18h3"/>',
        'visitors' => '<circle cx="10" cy="8" r="4"/><path d="M3 21v-2a7 7 0 0 1 12-4.9M18 18l3 3M20 16a4 4 0 1 1-8 0 4 4 0 0 1 8 0z"/>',
        'credit-card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'shield' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'hourglass' => '<path d="M6 2h12M6 22h12M7 2c0 5 5 5 5 10s-5 5-5 10M17 2c0 5-5 5-5 10s5 5 5 10"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.5 1.5c-1.2 1.1-2.1 1.3-2.1 3M12 17h.01"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'key' => '<circle cx="8" cy="15" r="5"/><path d="m11.5 11.5 9-9M17 6l2 2M14 9l2 2"/>',
        'pencil' => '<path d="m16 4 4 4M4 20l4-.8L19 8a2.8 2.8 0 0 0-4-4L4 15z"/>',
        'arrow-right' => '<path d="M4 12h15M13 5l7 7-7 7"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6M9 18h3"/>',
        'bolt' => '<path d="m13 2-3 8h7l-6 12 2-9H6z"/>',
        'bug' => '<path d="M12 8a4 4 0 0 1 4 4v5a4 4 0 0 1-8 0v-5a4 4 0 0 1 4-4zM9 3l3 3 3-3M4 13h4M16 13h4M5 7l3 3M19 7l-3 3M5 19l3-3M19 19l-3-3"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'sparkles' => '<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l1 2.5 2.5 1-2.5 1L19 23l-1-2.5-2.5-1 2.5-1z"/>',
        'pin' => '<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0z"/><circle cx="12" cy="10" r="2.5"/>',
        'scroll' => '<path d="M8 4h11v16H7a3 3 0 0 1-3-3V5a2 2 0 0 1 2-2h2v4H6M10 9h6M10 13h6M10 17h4"/>',
        'volume' => '<path d="M4 10v4h4l5 4V6l-5 4zM17 9a5 5 0 0 1 0 6M19 6a9 9 0 0 1 0 12"/>',
        'paw' => '<path d="M8 11c-1.5 0-2.5-1.5-2.5-3S6.5 5 8 5s2.5 1.5 2.5 3S9.5 11 8 11zM16 11c-1.5 0-2.5-1.5-2.5-3S14.5 5 16 5s2.5 1.5 2.5 3S17.5 11 16 11zM4.5 15c-1.2 0-2-.9-2-2s.8-2 2-2 2 1 2 2-.8 2-2 2zM19.5 15c-1.2 0-2-.9-2-2s.8-2 2-2 2 1 2 2-.8 2-2 2zM12 12c-2.7 0-6 4-6 6a2 2 0 0 0 2 2c1.5 0 2.5-1 4-1s2.5 1 4 1a2 2 0 0 0 2-2c0-2-3.3-6-6-6z"/>',
        'recycle' => '<path d="m7 7 2-3 3 1M17 7l3 1-1 4M16 18l-1 3-4-1M5 11l-2 3 3 2M9 5l3 3-2 3M19 13l-4 1-1 4M8 19l1-4-4-1"/>',
        'cigarette' => '<path d="M3 15h15v4H3zM18 15h2v4h-2M6 15V9M10 15V9M14 15V9M6 5c2 1 2 2 0 3M12 5c2 1 2 2 0 3"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'wallet' => '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M16 14h2"/>',
        'coin' => '<circle cx="12" cy="12" r="9"/><path d="M14.5 8.5c-.6-.7-1.4-1-2.5-1-1.4 0-2.5.8-2.5 2s1 1.8 2.5 2.2 2.5.8 2.5 2.2-1.1 2.2-2.5 2.2c-1.1 0-2-.4-2.7-1.2M12 5.5v13"/>',
        'edit' => '<path d="m16 4 4 4M4 20l4-.8L19 8a2.8 2.8 0 0 0-4-4L4 15z"/>',
        'car' => '<path d="m5 11 1.5-5h11L19 11M3 11h18v8H3zM6 19v2M18 19v2M6 15h.01M18 15h.01"/>',
        'check-square' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7 12 3 3 7-7"/>',
        'parking' => '<path d="M5 21V3h8a6 6 0 0 1 0 12H5"/><path d="M9 7v4h4a2 2 0 1 0 0-4z"/>',
        'image-search' => '<rect x="3" y="4" width="14" height="14" rx="2"/><circle cx="8" cy="9" r="1.5"/><path d="m4 16 4-4 3 3 2-2 3 3M19 19l3 3M21 17a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 0 4 22zM4 5.5v14A2.5 2.5 0 0 1 6.5 17H20"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'siren' => '<path d="M5 17h14l-1-9a6 6 0 0 0-12 0zM3 21h18M12 2V1M3 5 1.5 4M21 5l1.5-1M2 12H1M23 12h-1"/>',
        'celebrate' => '<path d="m12 3 1.2 4.8L18 9l-4.8 1.2L12 15l-1.2-4.8L6 9l4.8-1.2zM19 15l.7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7zM5 3v3M3.5 4.5h3M5 18v3M3.5 19.5h3"/>',
        'hand' => '<path d="M8 12V5a1.5 1.5 0 0 1 3 0v6-8a1.5 1.5 0 0 1 3 0v8-6a1.5 1.5 0 0 1 3 0v7-4a1.5 1.5 0 0 1 3 0v7c0 5-3 8-8 8h-1c-2 0-3.5-1-4.5-2.5L4 16a1.7 1.7 0 0 1 2.8-2z"/>',
        'paperclip' => '<path d="m8 12.5 7.8-7.8a3.5 3.5 0 0 1 5 5L10 20.5a5 5 0 0 1-7.1-7.1L14 2.3"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/>',
        'file-text' => '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h7"/>',
        'default' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    ];

    if (!isset($iconPaths[$name])) {
        $name = 'default';
    }

    return '<span class="sidebar-icon" aria-hidden="true"><svg class="system-icon-svg sidebar-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $iconPaths[$name] . '</svg></span>';
}

function systemIcon(string $name, string $class = 'system-icon'): string {
    $sidebarIcon = systemSidebarIcon($name);
    $sidebarIcon = str_replace('class="sidebar-icon"', 'class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"', $sidebarIcon);
    return str_replace(' class="system-icon-svg sidebar-icon-svg"', ' class="system-icon-svg"', $sidebarIcon);
}

function systemIconFromGlyph(string $glyph, string $class = 'system-icon'): string {
    $glyphToIcon = [
        '⌂' => 'dashboard', '🏠' => 'dashboard', '▦' => 'units', '♙' => 'residents', '♟' => 'staff', '!' => 'violations',
        '⏳' => 'hourglass', '▣' => 'wallet', '🧾' => 'bills', '⚠' => 'violations', '⚠️' => 'violations',
        '📅' => 'calendar', '🛠️' => 'maintenance', '🔧' => 'maintenance', '💬' => 'messages', '📢' => 'announcements',
        '📊' => 'analytics', '🚗' => 'car', '🅿️' => 'parking', '👤' => 'visitors', '👥' => 'users',
        '💳' => 'credit-card', '📈' => 'analytics', '🔐' => 'shield', '⏰' => 'clock', '◷' => 'clock',
        '✅' => 'check-circle', '✓' => 'check', '✖' => 'close', '✕' => 'close', '✉️' => 'mail',
        '🔑' => 'key', '✏️' => 'pencil', '➔' => 'arrow-right', '📋' => 'clipboard', '🔍' => 'search',
        '❓' => 'help', '↩️' => 'undo', '🔔' => 'bell', '☰' => 'menu', '₱' => 'coin', '📌' => 'pin',
        '📜' => 'scroll', '🔒' => 'lock', '⚡' => 'bolt', '🦠' => 'bug', '🚨' => 'siren',
        '🔊' => 'volume', '🐾' => 'paw', '♻️' => 'recycle', '🚭' => 'cigarette', '🎉' => 'celebrate',
    ];

    return systemIcon($glyphToIcon[$glyph] ?? 'default', $class);
}
