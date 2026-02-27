## 0.16.0 - 2026-02-27

### Added

#### Cognitive Phase Tracker

- **`CognitiveWave` component**: Canvas-based sinusoidal visualisation of today's cognitive rhythm. Renders the predicted wave (gradient sky→indigo stroke) with shaded Peak bands (emerald) and Trough bands (amber), plots completed tasks as dots sized by cognitive load. Uses an offscreen canvas for the static scene and a `requestAnimationFrame` loop that only blits + overdraws the "now" marker — sub-millisecond per frame, no layout shift.
- **`useCognitivePhase` hook**: Fetches phase snapshot + today's events when the `chronobiology` flag is on; refreshes every 5 minutes. Exposes `addOptimisticEvent()` so a completion dot appears on the wave the instant a task is marked done, before the backend job finishes recalibrating.
- **`sortByCognitivePhase()`** (`src/lib/cognitiveSort.ts`): Stable sort that bubbles high-effort tasks to the top during **Peak** and low-effort tasks during **Trough**; no-op in **Recovery**. Treats `cognitive_load: null` as 5 (neutral). Composes with the existing urgency sort — within equal-load tiers, urgency order is preserved.
- **Cognitive effort slider** in `ItemForm`: 1–10 range input inside "More settings", shown only when `chronobiology` is enabled. Value included in create/update payloads.
- **Deep Work banner**: When `next_peak_at` is ≤15 minutes away, shows an emerald callout: "Deep Work Window opening in X minutes — queue up a heavy task". Not gated on current phase classification — `next_peak_at` naturally handles the "already at apex" case (it jumps forward a full period). Trigger logic extracted into `minutesUntilDeepWork()` for direct unit testing of the 2:00→2:15 PM spec scenario.
- **`chronobiology` feature flag**: Third toggle in Settings ("Cognitive Rhythm Tracking"), defaults off. When disabled, no phase API calls are made and all rhythm UI is hidden.
- **API client**: `fetchCognitivePhase()`, `fetchCognitiveEvents()` (both target `/api/v1/…` — first versioned endpoints); `createItem`/`updateItem` now carry `cognitive_load`.
- **Types**: `CognitivePhase`, `CognitivePhaseSnapshot`, `CognitiveEventPoint`; `Item.cognitive_load`; `feature_flags.chronobiology`.

### Changed

- **Sort pipeline** in `page.tsx`: urgency sort → phase sort → project filter. Phase sort only applies when the flag is on _and_ a profile exists.
- **`onRowChange`**: Detects `status → "done"` transitions and fires `addOptimisticEvent` with the item's `cognitive_load` (or 5) for instant wave plotting.

### Testing

- `cognitive-phase.test.tsx`: 7 sort tests (Peak/Trough/Recovery ordering, null-as-5, stability, non-mutation) + 3 wave render tests (canvas present, no-profile message, null-snapshot fallback).
- All existing test fixtures updated with `cognitive_load: null`.

## 0.15.0 - 2026-02-08

### Added

#### Favourite Contacts in Assign Modal

- **Quick-pick favourites**: When no search query is entered (< 3 chars), the Assign Modal shows a "Favourites" section with one-click assignment buttons for starred contacts
- **Star toggle**: Search results include a ★ button to toggle favourite status. Starred users are highlighted with the star icon in results.
- **Favourites first**: Favourite contacts appear at the top of search results
- **`toggleFavourite()` API function**: `POST /api/favourites/{userId}` — toggles favourite status and refreshes user data via `onFavouriteToggled` callback

#### Real-Time Task State Management

- **Service Worker messages**: SW posts a `TASK_UPDATE` message to all open app windows when a push notification is received (even when user doesn't tap it), enabling foreground data refresh
- **SW message listener**: `page.tsx` listens for `TASK_UPDATE` messages and re-fetches the relevant item lists based on notification type (`task_assigned`, `task_unassigned`, `task_rejected`, `task_updated`, `task_completed`)
- **Visibility change refresh**: `page.tsx` re-fetches all item lists when the tab becomes visible (fallback for when SW is inactive)

### Changed

- **`AssignModal`**: Refactored to accept `favourites` and `onFavouriteToggled` as props instead of calling `useAuth()` directly — eliminates test-environment dependency on `AuthProvider`
- **`ItemForm`**: Passes `favourites` and `onFavouriteToggled` props through to `AssignModal`
- **`EditItemModal`**: Passes `favourites` and `onFavouriteToggled` props through to `ItemForm`
- **`page.tsx`**: Passes `user?.favourites` and `refreshUser` down to `EditItemModal`
- **`User` type**: Added `favourites?: UserLookup[]` field

## 1.0.0 - 2026-02-07

### Added

#### Backend-Persisted Feature Flags

- **Feature Flags Storage**: Feature flags (dates, delegation) now persist in user profile on backend
  - Added `feature_flags` JSON column to users table
  - Updated User model, controller, and resource to support feature flags
  - Feature flags sync across all devices and sessions
  - Flags stored as `{"dates": false, "delegation": false}` in database

- **AuthContext Enhancements**: Centralised user data management
  - `refreshUser()`: Fetch fresh user data from backend
  - `updateUser()`: Update user profile and sync state across app
  - Single user fetch on mount (eliminates redundant API calls)
  - Feature flags loaded automatically with user data

- **Optimised Data Fetching**: Eliminated redundant user fetches
  - AuthContext fetches user once on mount with feature flags
  - useFeatureFlags hook reads from AuthContext (no separate fetch)
  - Toggle updates both backend and local state immediately
  - No page reload required - UI updates reactively

### Changed

#### Full-Screen Modal UX

- **NotesModal**: Converted from bottom-sheet to full-screen centered modal for better usability
  - Changed layout from `items-end` to `items-center justify-center`
  - Increased width from `max-w-md` to `w-[90vw] max-w-2xl` for more screen real estate
  - Changed border radius from `rounded-t-2xl` to `rounded-lg` (all corners)
  - Added `max-h-[90vh] overflow-y-auto` for proper scrolling
  - Increased textarea `min-h` from `[200px]` to `[400px]` for more editing space
  - Updated backdrop opacity from `bg-black/40` to `bg-black/50`
  - Animation changed to `animate-scale-in` for full-screen entrance

- **EditItemModal**: New full-screen modal component for creating and editing tasks
  - Replaces inline ItemForm rendering with modal-based editing
  - Provides consistent modal pattern across Notes and Task editing
  - Features: ESC key support, click-outside to close, proper header with close button
  - Modal wrapper around ItemForm with all form fields accessible
  - Improved focus and better mobile/desktop app experience

- **Home Page**: Updated task editing flow to use EditItemModal
  - Removed inline ItemForm rendering for both create and edit operations
  - Add button now triggers full-screen modal instead of inline form
  - Edit item row click triggers full-screen modal
  - All task CRUD operations now use consistent modal pattern
  - Improved state management with `showEditModal` and `editingItem`

### Technical

- **CSS Animations**: Verified `animate-scale-in` animation exists for full-screen modal entrance
- **Component Architecture**: Cleaner separation between display (ItemRow) and editing (EditItemModal)
- **UX Consistency**: Unified modal pattern for all editing operations

## 0.9.0 - 2026-02-06

### Added

#### Notes Scratchpad

- **NotesModal**: New bottom-sheet modal providing a free-text scratchpad persisted on the backend
- **Auto-Save**: Notes save automatically on close if content has changed, with "Saving..." / "Saved" indicator
- **Character Limit**: 10,000 character maximum with live counter
- **Bottom Bar**: Notes button (notepad icon) added between Settings and Filter; Add button remains centred via 3-column grid layout
- **API Functions**: Added `fetchUser()` and `updateUser()` for user profile reads and updates

### Changed

- **User Type**: Added `notes` field to `User` interface
- **Bottom Bar Layout**: Changed from flex `justify-between` to CSS grid `1fr auto 1fr` to keep the Add button centred with 4 items

## 0.8.0 - 2026-02-06

### Added

#### Feature Flags

- **Feature Flags Hook**: New `useFeatureFlags()` hook backed by localStorage for toggling optional features on/off
- **Settings Toggles**: "Features" section in Settings modal with toggle switches for "Dates & Scheduling" and "Task Delegation"
- **Dates Flag**: When off, hides scheduled dates, due dates, recurrence fields, urgency styling, planned view toggle, and urgency-based sorting
- **Delegation Flag**: When off, hides "Assigned to Me" section, "Delegated to Others" section, assignment badges, assignee notes, decline button, and skips delegation API calls

### Removed

- **Insights Page**: Removed `/insights` route, Heatmap component, and CategoryChart component
- **Stats API**: Removed `fetchStats()` function and `HeatmapEntry`, `CategoryDistribution`, `StatsResponse` types
- **Settings Insights Button**: Removed Insights navigation button from Settings modal

### Changed

- **ItemForm**: Date fields (scheduled date, due date, repeat, if missed) and delegation fields (assignee notes, assign to) are conditionally rendered based on feature flags
- **ItemRow**: Recurrence icon, due/scheduled date displays, urgency border styling, delegation badges, and assignee notes are conditionally rendered based on feature flags
- **Home Page**: Urgency sorting, planned view toggle, delegation sections, and delegation API calls are conditionally gated by feature flags
- **Version**: Bumped to v0.11.0

## 0.7.0 - 2026-01-31

### Added

#### Web Push Notifications (Phase 2 & 3)

- **Service Worker Push Handler**: SW now listens for push events and displays native notifications
- **Notification Click Handler**: Clicking notifications navigates to the relevant URL
- **Push Subscription Hook**: `usePushNotifications()` hook for managing browser push subscriptions
- **Settings Modal**: New settings modal accessible from the bottom bar
- **Push Toggle Component**: Toggle to enable/disable push notifications per device
- **API Functions**: Added `getVapidPublicKey()`, `subscribeToPush()`, `unsubscribeFromPush()`, `listPushSubscriptions()`, `deletePushSubscription()`

### Changed

- **Bottom Bar**: Settings button now opens the settings modal instead of alert
- **Service Worker**: Extended with push and notificationclick event handlers

## 0.6.0 - 2026-01-25

### Added

#### Task Assignment

- **Assign Modal**: New component for searching and selecting users to assign tasks to
- **User Search**: Search users by email or last 9 digits of phone number
- **Assignment in Forms**: ItemForm now includes "Assign to" field in "More settings" section
- **Visual Indicators**: ItemRow shows badges for delegated tasks and tasks assigned from others

#### View Modes

- **My Tasks**: Default view showing unassigned items you own
- **Assigned to Me**: View items others have assigned to you
- **Delegated**: View items you've assigned to others
- **View Selector**: Tab-style selector with item counts for each view

#### Notifications (poll-based)

- **Notification API Functions**: Added `fetchNotifications()`, `markNotificationAsRead()`, `markAllNotificationsAsRead()` functions
- **Notification Types**: Added `Notification` and `NotificationsResponse` types

### Changed

- **Item Type**: Extended with `assignee_id`, `assignee`, `owner`, `is_assigned`, `is_delegated`, and scheduling fields
- **User Type**: Added `phone` field
- **API Client**: Added `lookupUsers()`, `assignItem()`, and notification functions
- **fetchItems**: Now supports `assigned_to_me` and `delegated` filter parameters
- **createItem/updateItem**: Now support `assignee_id` parameter

#### Insights (merged from parallel branch)

- **Insights Page**: New `/insights` route displaying a GitHub-style activity heatmap and a horizontal bar chart of completed items grouped by project.
- **Heatmap Component**: Scrollable week-column grid coloured by daily completion intensity (4-level sky-blue scale). Shows month labels, day-of-week labels, a legend, and a total-completed summary.
- **CategoryChart Component**: Horizontal progress bars per project, coloured with each project's assigned colour, sorted by count descending.
- **API Integration**: Added `fetchStats` to the API client, fetching heatmap and category-distribution data from `GET /api/stats` with optional date-range parameters.
- **Nav Wiring**: Insights page accessible via the Settings modal.
- **Types**: Added `HeatmapEntry`, `CategoryDistribution`, and `StatsResponse` interfaces.

## 0.5.1 - 2026-01-17

- **Deployment Fix**: Fixed Azure Web App deployment workflow - corrected package path from `./node-app` to `.` to match artifact extraction location.
- **Build Strategy**: Refactored CI/CD to build on Azure instead of GitHub Actions - source code is now uploaded to Azure which builds using environment variables from Azure portal.
- **CI/CD Quality Gates**: Separated lint and test from build - CI/CD now runs lint and test as quality gates before deployment, while Azure handles the build.
- **Environment Variable Simplification**: Azure environment variables (`NEXT_PUBLIC_API_BASE_URL`) now work directly during build without needing GitHub variable configuration.

## 0.5.0 - 2026-01-15

- **Project Selection**: Tasks can now be assigned to projects when adding or editing. Select from existing projects or create new ones inline with a searchable combobox.
- **ProjectCombobox Component**: New reusable combobox with search filtering, keyboard navigation, and instant inline project creation.
- **API Integration**: Added `fetchProjects` and `createProject` functions to fetch and create projects via the backend API.
- **ItemForm Enhancement**: "More settings" now includes a project selector with labels for clearer UI.
- **Test Coverage**: Added comprehensive tests for project selection and inline creation flow.

## 0.4.5 - 2025-12-10

- **Drag-and-Drop Reordering**: Replaced up/down arrows with drag handles so tasks can be reordered directly, while keeping the move-to-top shortcut.
- **Backend Persistence**: Item order now saves to the backend after a drop to keep lists consistent across devices.
- **Test Coverage**: Added drag-and-drop regression tests and updated ItemRow specs for the new controls.

## 0.4.4 - 2025-12-10

- **README Glow-Up**: Rebuilt the README with a colourful, infographic-style quickstart that reflects the current REST API backend, auth flow, and testing commands.
- **Getting Started Accuracy**: Removed outdated Google Sheets guidance in favour of the `NEXT_PUBLIC_API_BASE_URL` Laravel-style API setup and refreshed deploy notes.

## 0.4.3 - 2025-12-10

- **Create Response Normalisation**: API client now unwraps `{ data: item }` envelopes so optimistic tasks stay intact once the server replies.
- **UI Resilience**: Home page normalises create responses before replacing optimistic entries, preventing blank rows when responses are wrapped.
- **Test Coverage**: Added regression test to ensure wrapped create responses no longer wipe item details after submission.

## 0.4.2 - 2025-12-10

- **React Key Prop Fix**: Fixed "Each child in a list should have a unique key prop" error when submitting items
- **Unique Temporary IDs**: Improved temporary ID generation for optimistic items using timestamp + random string to prevent conflicts
- **Key Prop Safety**: Added fallback key prop using index when item.id is missing to prevent React warnings
- **Duplicate Prevention**: Added checks to prevent duplicate IDs when adding optimistic items to the list
- **Test Updates**: Updated test regex pattern to match new temporary ID format

## 0.4.1 - 2025-12-10

- **Login Redirect Fix**: Moved authenticated redirect into a layout effect-friendly hook to avoid Router updates during render
- **API Resilience**: fetchItems now tolerates paginated `{ data: [] }` responses before filtering statuses, preventing runtime crashes
- **Test Coverage**: Added regression tests for login redirect behaviour and paginated fetchItems responses

## 0.4.0 - 2025-12-10

- **Backend Migration**: Migrated from Google Sheets to RESTful Laravel API backend
- **Full Authentication**: Implemented complete authentication flow with login, registration, and logout
- **Token-Based Auth**: Uses localStorage for persistent token storage with Bearer token authentication
- **New Status Values**: Updated status values to match backend (`todo`, `doing`, `done`, `wontdo`)
- **Updated Item Schema**: Item model now includes `user_id`, `project_id`, `description`, `position`, `created_at`, `updated_at`, `deleted_at`
- **Auth Context**: Added React context for app-wide authentication state management
- **Protected Routes**: Home page now requires authentication, redirects to login if not authenticated
- **Login Page**: New login page with form validation and error handling
- **Registration Page**: New registration page with password confirmation and validation
- **Logout Button**: Settings icon replaced with logout button in header
- **API Client Rewrite**: Completely rewrote API client to use RESTful endpoints with auth headers
- **Removed Google Sheets**: Removed all Google Apps Script proxy routes and local store fallback
- **Test Updates**: Updated all tests for new status values, Item interface, and auth mocking
- **Environment Config**: Now uses `NEXT_PUBLIC_API_BASE_URL` for backend API URL

## 0.3.7 - 2025-10-25

- **Item Addition Behaviour**: New items are now added to the bottom of the list instead of the top
- **Auto-scroll Feature**: The screen automatically scrolls to newly added items to ensure they're visible
- **Improved UX**: Better user experience when adding tasks - users can immediately see their new tasks at the bottom
- **Backend Enhancement**: Updated both frontend and backend to consistently add items to the end of the list
- **Test Coverage**: Added comprehensive tests to verify new item addition order and scroll behaviour

## 0.3.6 - 2025-10-24

- **Critical Bug Fix**: Fixed disappearing text issue when adding new items
- **Response Handling**: Fixed server response handling for Google Apps Script integration where GAS returns incomplete item data
- **API Enhancement**: Modified API route to reconstruct full item data from GAS metadata responses
- **Optimistic Updates**: Improved optimistic update handling to properly replace temporary items with server responses
- **Test Coverage**: Added comprehensive tests to verify item creation response handling for both local and GAS formats

## 0.3.5 - 2025-10-08

- **Build Fix**: Fixed GitHub Actions build failure caused by ESLint error in move-to-top test file
- **Code Quality**: Replaced forbidden `require()` statement with ES6 import in test file to comply with TypeScript ESLint rules

## 0.3.4 - 2025-10-08

- **Move to Top Feature**: Added double up arrow button (⇈) to quickly move items to the top of the list
- **Enhanced Reordering**: New "Move to Top" button appears to the left of up/down arrows for all non-first items
- **Optimistic Updates**: Move to top operation updates UI immediately with background API synchronization
- **API Enhancement**: Extended move API to support "top" direction in addition to "up" and "down"
- **Test Coverage**: Added comprehensive tests for move to top functionality

## 0.3.3 - 2025-08-28

- **Task Filtering**: Automatically filter out completed and cancelled tasks when fetching the task list
- **Cleaner Task View**: Only active tasks (pending, in_progress) are displayed in the main task list
- **Improved Focus**: Users now see only actionable tasks, reducing visual clutter from completed work
- **API Enhancement**: Modified fetchItems function to exclude "done" and "cancelled" status tasks from list action
- **Maintained Functionality**: All other API operations (add, update, move, delete) continue to work with all task statuses
- **Test Coverage**: Added comprehensive test to verify filtering functionality works correctly

## 0.3.2 - 2025-08-28

- **Optimistic Updates**: Implemented immediate UI updates for all user actions without waiting for API responses
- **Instant Status Changes**: Status circle clicks now update the UI immediately while sending API requests in the background
- **Real-time Reordering**: Move operations (up/down arrows) now update the UI instantly for better user experience
- **Immediate Add/Edit**: New tasks and edits appear in the UI immediately with background API synchronization
- **Background API Calls**: All operations now use "fire and forget" pattern - UI updates instantly, API calls happen asynchronously
- **Fully Optimistic Edits**: Edit forms close immediately upon submission, no waiting for server confirmation
- **Persistent Optimistic State**: User changes remain visible even if API calls fail, maintaining workflow continuity
- **Error Handling**: Failed API calls are logged and can optionally trigger UI rollbacks or user notifications
- **Enhanced UX**: Significantly improved perceived performance and responsiveness across all user interactions
- **Critical Bug Fix**: Fixed "items.map is not a function" runtime error that was crashing the application
- **API Response Validation**: Fixed moveItem function to always return arrays and handle invalid API responses gracefully
- **State Safety**: Added comprehensive array validation to prevent state corruption during optimistic updates
- **Robust Error Handling**: Added comprehensive error handling to prevent UI crashes during optimistic operations

## 0.3.1 - 2025-08-28

- **Fixed CORS Issue**: Resolved CORS error with `?action=update` API calls by adding proper CORS middleware
- **API Middleware**: Added Next.js middleware to handle CORS preflight requests and add CORS headers to all API responses
- **Cross-Origin Support**: Now properly supports cross-origin requests for all API actions (list, add, update, move, delete)
- **Google Apps Script Proxy**: Implemented proxy solution to route all Google Apps Script calls through Next.js API, eliminating CORS issues entirely
- **Seamless Integration**: Frontend now always calls local API endpoints, which proxy to Google Apps Script when configured
- **Fallback Support**: Maintains local store functionality when Google Apps Script is unavailable
- **Production Ready**: Solution works in both development and production environments without CORS configuration

## 0.3.0 - 2025-08-28

- **Major UI Redesign**: Completely redesigned the application to match the exact design specification
- **Enhanced Task Management**:
  - Added start date and due date fields (replacing single date field)
  - Removed status selection for new tasks (always pending)
  - Added collapsible "More settings" section for project, notes, and dates
  - More settings closed by default for new tasks, open by default for editing
- **Improved User Experience**:
  - Task rows are now clickable to open edit dialog
  - Notes are displayed underneath the category in smaller font
  - **Up/down arrows are now visible on every row** (not hidden in collapsible section)
  - First row shows only down arrow, last row shows only up arrow, middle rows show both
  - Status circles use proper SVG icons instead of emojis
- **UI Polish**:
  - Added footer with "Psycode Pty. Ltd. © 2025"
  - Updated all components to use new data structure
  - Fixed all tests to match new UI implementation
  - Maintained Nunito font family throughout
- **API Improvements**:
  - Fixed column mapping to properly handle GAS data structure (id, task, project, status, notes, created_at, updated_at, due_date)
  - Added fallback to local API when GAS endpoint is not available
  - Ensured proper data display in the UI

## 0.2.4 - 2025-08-28

- Implemented Nunito font family using next/font/google for improved typography
- Added filter and settings icons to header (funnel and gear icons)
- Redesigned quick-add row with blue plus circle and "Add new task..." placeholder
- Enhanced ItemRow styling with strikethrough for completed tasks and blue project names
- Updated tests to use new quick-add button entrypoint
- Fixed Next.js font loader module scope requirement

## 0.2.3 - 2025-08-27

- Fixed React 19 optimistic state update warning in ItemRow component
- Implemented proper React 19 useOptimistic pattern with reducer function
- Wrapped addOptimistic calls with startTransition for proper React 19 compatibility
- Resolved console errors about state updates outside transitions

## 0.2.1 - 2025-08-27

- Fixed all ESLint warnings and code quality issues
- Updated PostCSS and Tailwind config files to follow ESLint best practices
- Removed unused ESLint disable directives
- Fixed unused parameter in API route handlers

## 0.2.0 - 2025-08-27

- Fixed Tailwind CSS compilation issue at build time
- Downgraded from Tailwind CSS v4 to v3 for better Next.js/Turbopack compatibility
- Added proper tailwind.config.ts with content paths configuration
- Resolved CSS utility classes not being included in production builds

## 0.1.2 - 2025-08-27

- Enhanced micro-animations and UX polish
- Added beautiful empty state illustrations with loading, no-items, and no-results states
- Improved StatusCircle with hover effects, scale animations, and status-specific styling
- Enhanced ItemRow with staggered slide-in animations and hover effects
- Added loading states with skeleton UI and spinner animations
- Improved ItemForm with focus states, transitions, and better button interactions
- Added custom CSS keyframes for fade-in, slide-in-up, scale-in, and bounce-in animations
- Enhanced quick-add UX with smooth transitions and better visual feedback

## 0.1.1 - 2025-08-27

- Initial bullet journalling app scaffold (Next.js + Tailwind)
- Mobile-first list UI with status cycle and move controls
- Dummy API endpoints (placeholder for Google Sheets integration)
- Filtering by project/date/status
- Tests: status cycle and row interaction; added ./runtests.sh

## 0.1.0 - 2025-08-27

- Wired client to Google Apps Script backend via env var `NEXT_PUBLIC_GAS_BASE_URL`
- Implemented list/search (`?action=list`), add (`?action=add`), update (`?action=update`), move (`?action=move`)
- Hooked filter UI to remote queries; added add/edit/delete/archiving
