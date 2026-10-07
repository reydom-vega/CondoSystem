# Live Frontend Updates System

## Overview
The Celandine Residences application now includes automatic live updates for all pages without requiring manual browser refreshes. Messages, notifications, and dashboard metrics update in real-time.

## Features

### 1. **Live Messages**
- Messages page automatically refreshes every 2 seconds
- New messages appear instantly without page reload
- Auto-scrolls to latest message
- Works for both resident and admin messaging

**Files:**
- `resident/messages.php` - Live message polling for residents
- `superadmin/admin_messages.php` - Live message polling for admins
- `api/messages.php` - API endpoint for resident messages
- `api/admin_messages.php` - API endpoint for admin messages

### 2. **Live Dashboard Updates**
- Notification badges update automatically
- Unread message counts refresh every 3 seconds
- Maintenance request counts update
- Payment due notifications appear instantly
- Works when browser tab is in foreground (pauses when hidden)

**Files:**
- `js/live-updates.js` - Main live update script for all pages
- `api/dashboard.php` - API endpoint for resident dashboard
- `api/admin_dashboard.php` - API endpoint for admin dashboard

## How It Works

### Architecture

1. **Polling System**
   - Frontend polls API endpoints every 2-3 seconds
   - Only polls when browser tab is visible (pauses when hidden)
   - Uses fetch API with cache-busting timestamp

2. **API Endpoints** (JSON responses)
   - `/api/messages.php` - Returns resident's messages
   - `/api/admin_messages.php` - Returns admin's resident conversation
   - `/api/dashboard.php` - Returns resident dashboard metrics
   - `/api/admin_dashboard.php` - Returns admin dashboard metrics

3. **DOM Updates**
   - JavaScript compares new data with existing DOM
   - Only adds new messages (doesn't duplicate)
   - Updates badges with animation pulse effect
   - Auto-scrolls to latest message

## Configuration

### Polling Intervals

In `js/live-updates.js`:
```javascript
const CONFIG = {
    pollInterval: 3000,  // 3 seconds for dashboard updates
    // Message polling is 2 seconds (defined in messages.php)
};
```

Modify these values to adjust update frequency.

### Pause on Hidden Tab

The system automatically pauses polling when:
- Browser tab is not in focus
- Window is minimized
- Page visibility changes

This improves performance and reduces server load.

## Usage

### For Developers

To add live updates to a new page:

1. Create an API endpoint that returns JSON data
2. Add a fetch call to poll the endpoint
3. Update DOM based on the response
4. Include `<script src="../js/live-updates.js"></script>` for global updates

### For Users

Live updates work automatically:
- Open any message thread - new messages appear instantly
- Stay on dashboard - see updated notification counts
- No manual refresh needed
- Works best with modern browsers (Chrome, Firefox, Safari, Edge)

## Browser Compatibility

- Chrome/Edge: ✅ Fully supported
- Firefox: ✅ Fully supported
- Safari: ✅ Fully supported
- IE 11: ⚠️ Requires polyfills (fetch, Promise)

## Performance Notes

- Minimal server load (lightweight JSON responses)
- Only fetches data when tab is visible
- Uses cache-busting timestamps to prevent caching
- Graceful error handling (silently retries)
- No WebSocket/real-time overhead

## Future Improvements

Potential enhancements:

1. **WebSocket Support**
   - Real-time bidirectional communication
   - Instant updates without polling
   - Requires additional server infrastructure

2. **Service Workers**
   - Background sync for offline scenarios
   - Push notifications for mobile

3. **Sound Notifications**
   - Optional audio alert for new messages
   - Disabled by default

4. **Update Summary**
   - Show count of updates since last check
   - Option to view change log

## Testing

### Check Live Updates:

1. **Messages Test**
   - Open messages page in two browser windows
   - Send message from one window
   - Other window updates automatically in ~2 seconds

2. **Dashboard Test**
   - Open dashboard in one window
   - Create new notification/message in another
   - Badge counts update automatically in ~3 seconds

3. **Browser Tab Test**
   - Open page and switch browser tabs
   - Polling pauses (check Console for updates stop)
   - Switch back to tab
   - Updates resume (immediate sync)

### Debug Console

Check browser console (F12) for any fetch errors:
```
Failed to fetch updates: Error...
```

Most errors are logged but don't break functionality.

## Troubleshooting

### Updates Not Working

1. **Check API Endpoints**
   - Open DevTools (F12) → Network tab
   - Look for requests to `/api/messages.php` etc
   - Should see requests every 2-3 seconds

2. **Check Console Errors**
   - DevTools → Console tab
   - Look for any JavaScript errors
   - Check fetch() error messages

3. **Check Permissions**
   - Ensure logged in
   - Verify API files are accessible

### High CPU Usage

- Reduce poll interval in `js/live-updates.js`
- Disable live updates for less critical pages
- Use WebSockets instead (future improvement)

## Support

For issues with live updates:
1. Check browser console for errors
2. Verify API endpoints are returning valid JSON
3. Ensure user is logged in with proper permissions
4. Check network tab for failed requests
