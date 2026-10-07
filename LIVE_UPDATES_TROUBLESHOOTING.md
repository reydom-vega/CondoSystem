# Live Updates - Troubleshooting & Testing Guide

## Quick Start - Test Live Updates

### Step 1: Open Debug Console
1. Go to `http://localhost/Condo_System/debug_live_updates.html`
2. You should see a debug console with test buttons

### Step 2: Test the APIs
1. Click "Test Resident Messages API" - should show messages
2. Click "Test Admin Messages API" - should show resident's messages  
3. Click "Test Dashboard API" - should show dashboard metrics

If any of these fail, the APIs aren't accessible. Check the error message.

### Step 3: Test Live Message Updates
1. Open `http://localhost/Condo_System/resident/messages.php` in **Browser 1**
2. Open `http://localhost/Condo_System/superadmin/admin_messages.php` in **Browser 2** (or different tab)
3. Open **Browser Developer Tools** in each window (Press F12)
4. Go to **Console** tab and look for messages like:
   - `🔄 Message live updates enabled (polling every 2s)`
   - Every 2 seconds you should see updates being fetched

5. In **Browser 1** (resident), send a message
6. In **Browser 2** (admin), you should see the message appear **within 2-3 seconds** without refreshing

## What to Look For in Browser Console

### Good Signs ✅
```
🔄 Message live updates enabled (polling every 2s)
📍 Page visible, resuming message polling
✓ 1 new message(s) added
```

### Bad Signs ❌
```
Message update failed: TypeError: Cannot read property 'messages' of undefined
Admin message update failed: 404 Not Found
```

## Common Issues & Fixes

### Issue: "Cannot read property 'messages'"
**Cause**: API is returning error, not success
**Fix**: Test the API using the debug console. Check if you're logged in.

### Issue: "404 Not Found"
**Cause**: API file path is wrong
**Fix**: The paths have been updated to work with absolute URLs. Make sure /Condo_System/ is in the URL if accessing locally.

### Issue: Messages don't update even after 5+ seconds
**Cause**: Polling might be paused or not started
**Fix**: 
1. Check browser console for errors
2. Make sure browser tab is in focus (visible)
3. Hard refresh page (Ctrl+Shift+R)
4. Check if API endpoints are accessible

### Issue: Same message appears multiple times
**Cause**: Messages don't have unique IDs
**Fix**: This has been fixed. All messages should now have `data-message-id` attributes.

## Advanced Debugging

### Check if Polling is Happening
1. Open DevTools (F12)
2. Go to **Network** tab
3. Look for requests to `/api/messages.php` or `/api/admin_messages.php`
4. You should see new requests every 2 seconds
5. Click on a request and check **Response** tab to see JSON data

### Check Message Data Structure
1. Open DevTools (F12)
2. Go to **Console** tab
3. Type: `document.querySelectorAll('[data-message-id]')`
4. You should see a list of message elements with IDs
5. If empty, messages weren't rendered with IDs

### Manual API Testing via Console
```javascript
// Test resident messages API
fetch('/Condo_System/api/messages.php')
    .then(r => r.json())
    .then(data => console.log(data))

// Test admin messages API (replace 1 with actual user ID)
fetch('/Condo_System/api/admin_messages.php?user_id=1')
    .then(r => r.json())
    .then(data => console.log(data))
```

## How Live Updates Work

### Flow Diagram
```
Browser 1 (Resident) ← Every 2 seconds → /api/messages.php
                                              ↓
                                         Database
                                              ↑
Browser 2 (Admin)    ← Every 2 seconds → /api/admin_messages.php
```

### Process
1. Page loads with initial messages
2. JavaScript adds `data-message-id` to all message elements
3. Every 2 seconds, JavaScript fetches latest messages from API
4. API returns ALL messages for that user (for deduplication)
5. JavaScript compares message IDs
6. Only NEW messages (with IDs not in DOM) are added
7. Page auto-scrolls to latest message

## Testing Checklist

- [ ] API endpoints return valid JSON (test via debug console)
- [ ] Browser console shows polling messages (check F12 → Console)
- [ ] Network tab shows API requests every 2 seconds (check F12 → Network)
- [ ] Message elements have `data-message-id` attributes
- [ ] Sending message from one browser shows in other within 2-3 seconds
- [ ] No console errors about fetch or DOM manipulation
- [ ] Polling pauses when switching tabs (check Network tab)
- [ ] Polling resumes when returning to page

## Performance Notes

- Polling every 2 seconds is reasonable
- Each API call is lightweight (just JSON data)
- No real-time events or WebSockets needed
- Works on all browsers (Chrome, Firefox, Safari, Edge)

## Still Having Issues?

1. **Verify you're logged in** - APIs require authentication
2. **Check file paths** - ensure `/Condo_System/` is in URL if on localhost
3. **Clear browser cache** - Ctrl+Shift+Delete in Chrome
4. **Hard refresh page** - Ctrl+Shift+R
5. **Check error_log** - look for PHP errors in logs

## Contact Support

If live updates still don't work after these steps:
1. Provide screenshots of:
   - Browser console (F12 → Console tab)
   - Network tab showing API requests
   - The error messages you see
2. Describe:
   - What browsers you're using
   - Whether APIs work when tested manually
   - If messages work on page reload
