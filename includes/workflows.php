<?php
/** Shared request protection and strict date validation for resident services. */
function workflowCsrfToken(): string {
    return $_SESSION['workflow_csrf'] ??= bin2hex(random_bytes(32));
}
function workflowCsrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(workflowCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}
function requireWorkflowCsrf(?string $token = null): void {
    $token ??= is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($token === '' || !hash_equals(workflowCsrfToken(), $token)) {
        http_response_code(403);
        if (!str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')) {
            renderPortalError('Your session token is invalid. Refresh the page and try again.', 'Refresh your session');
            exit;
        }
        exit('Your session token is invalid. Refresh the page and try again.');
    }
}
function workflowDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function canReviewPermits(): bool {
    return isLoggedIn() && in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);
}
