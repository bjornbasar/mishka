# Mishka Den — User Guide

A family hub for the household calendar, chores, and the everyday coordination a family needs. This guide walks you from your first signup through every feature.

> Looking for the developer / architecture docs? See [DOCS.md](../DOCS.md) instead.

---

## The mental model (read this first)

Three concepts that everything else depends on:

1. **One Mishka can host many households.** Each family is its own household — completely separate calendar, chores, members. You don't see anyone else's data, ever.
2. **You can belong to more than one household.** A divorced parent, a foster carer, a live-in nanny — any of these can be a member of more than one household and switch between them with one click. Each membership stands on its own.
3. **Every household has exactly one owner.** The person who created it. The owner can rename it, transfer ownership to another member, remove other members, regenerate the invite code, or delete the whole thing. Members can do everything else (add chores, edit events, mark things done) but can't kick people or delete the household.

If you're setting Mishka up for the first time, **you'll create the household** during signup and be its owner. Other family members register their own accounts and then **join** your household using a short code you share with them.

---

## 1. Getting started

### Sign up

Go to `/register` (linked from the front page).

You'll need:
- **Email** — used to sign in, recover a forgotten password, and verify your account. Pick carefully — Mishka v0.5 doesn't let you change it later. Use an email you'll keep.
- **Display name** *(optional)* — what other family members see. If you skip it, Mishka uses the local part of your email (`bjorn@…` becomes `bjorn`).
- **Password** — 12–128 characters. No specific complexity rules; longer is better.

Click **Create account**.

### Verify your email

Right after registering, Mishka sends a verification email. You'll see a soft banner at the top of every page that says **"Please verify your email — Resend"** until you confirm.

- Open your inbox. The email is from `noreply@mishka.minified.work` (or whichever address your operator configured).
- Click the link inside. The banner disappears.
- The link is single-use and expires in 24 hours. Lost it? Sign in and click **Resend** on the banner.

You can use Mishka without verifying — nothing's blocked — but the banner stays until you do.

### Create or join a household

After signup you land on **/household/setup**. Two choices:

**Create a household** (you'll be the owner):
1. Pick a name (e.g., "The Basars", "Apartment 4B", "Smith Family"). Up to 120 characters.
2. Click **Create**.
3. Mishka shows you an 8-character **invite code** (e.g., `MZ7K2BPN`). Share it with the people you want in your household.

**Join a household** (someone else is the owner):
1. Get the invite code from them.
2. Type it in, click **Join**.

That's it. You're in. The top nav switches to show Calendar / Chores / Household / Feed.

---

## 2. The calendar

Click **Calendar** in the top nav.

### Add a one-off event

1. Click **+ New event**.
2. Fill in title, start, end, location (optional), description (optional).
3. Click **Save**.

Times are in your household's timezone (Pacific/Auckland by default). All members see the same time on their screens regardless of where they're connecting from.

### Recurring events

When creating an event, set the **Repeats** dropdown:

- **Does not repeat** *(default)*
- **Daily** *(every day from the start)*
- **Weekly** — tick any combination of Mon/Tue/Wed/Thu/Fri/Sat/Sun. So "MWF" is Mon+Wed+Fri checked.
- **Monthly** — pick a day-of-month (1–28).
- **Yearly** — same day each year (good for birthdays).
- **Repeat every** — combine with any of the above. "Repeat every 2 weeks on Tue" for fortnightly Tuesday.

The series carries on forever until you delete it. Each future occurrence shows on the calendar at the right date.

### Edit a single occurrence (without affecting the series)

Click any event in the grid → you'll get the option to edit JUST this occurrence (e.g., "next Wednesday is moved to Thursday because it's a holiday") or the WHOLE series.

### Subscribe from your phone

Mishka generates a per-user secret URL you can paste into Apple Calendar, Google Calendar, Outlook, etc. Click **Feed** in the top nav. You'll see your subscribed URL and a button to revoke it if it ever leaks.

The feed updates in near-real-time — events you add appear in your phone calendar within minutes.

---

## 3. Chores

Click **Chores** in the top nav.

### One-off chores

1. Click **+ New chore**.
2. Fill in: title, points (0–1000), due date *(optional)*, assignee *(optional — pick any household member, or leave unassigned)*.
3. Save.

The chore shows up under **To do** for whoever's assigned, ordered by due date.

To complete: click **✓ Done** on the card. The doer gets the points; the chore moves to the **Done** section below.

To reopen: expand **Done**, find the chore, click **Reopen**. Points get un-credited.

### Recurring chores

Click **+ New recurring chore** (instead of new chore).

Same form as one-off, plus a **Repeats** section with four shapes:

- **Daily** — once per day starting from the anchor date.
- **Weekly** — tick any combination of days. Need M/W/F? Tick three boxes. Tue + Sat? Tick those two.
- **Monthly: on day N** — e.g., on the 15th of every month (1–28; days 29-31 omitted because they don't exist in every month).
- **Monthly: on the [Nth] [Weekday]** — e.g., "1st Friday of the month", "3rd Tuesday", "Last Sunday". Five positions × seven days = 35 combinations.
- **Yearly** — same date each year. Useful for annual chores like "renew passport" or "check smoke alarms."
- **Repeat every** — combine. "Repeat every 2 weeks on Tue" for fortnightly Tuesday bin night.

Each generated occurrence becomes a real chore in the **To do** list at the right time.

### Rotation vs fixed

Recurring chores have two assignment modes:

- **Rotate** *(default)* — Mishka cycles through household members in join order, one per occurrence. Add nobody to the participant list → rotates across everyone. Add specific members → rotates only across those.
- **Always assign to** — pick one member; every occurrence lands on them. Good for "Adult clothes — Bjorn" if one person owns it.

### Pause / resume

If you're going on holiday or the chore is temporarily not needed, click **Pause** next to the recurring chore. Existing occurrences stay; no new ones are generated until you click **Resume**. Resuming rewinds the clock to "now," so a long pause doesn't dump 20 backlogged chores in one go.

---

## 4. Gamification

Mishka tracks who's doing what without turning chores into a chore.

### Points

Each completed chore credits the doer with its point value. Two tallies surface:
- **Week points** — Monday 00:00 (household timezone) to the end of Sunday. Resets each Monday.
- **All-time points** — every chore ever.

Both show on the **Leaderboard** at the top of the Chores page and on the Home page.

### Badges

Earned the moment the criterion is met:
- 🌱 **First chore** — your very first one
- ⭐ **10 chores**
- 🏅 **50 chores**
- 💯 **100 points** (all-time)
- 🏆 **500 points** (all-time)
- 🔥 **Four-week streak** — completed at least one chore in four consecutive weeks

Hover any badge on the leaderboard for the description. Badges are derived live from your history; there's no separate "earn date" — if you have the points/count, you have the badge.

### Streaks

A 🔥 next to your name on the leaderboard shows how many consecutive weeks you've completed at least one chore. Forgiving — within the current week you haven't broken it; it only resets after a fully missed Monday-to-Sunday week. DST-safe.

### Missed-chore tally

A ⏰ icon shows how many chores assigned to you are past their due date and still not done. **You don't lose points** — it's just a heads-up. The icon only appears when you have any (zero is hidden).

---

## 5. Households

### Inviting members

The household's invite code is on the **Household** page (visible only to the owner). It's an 8-character code from a friendly alphabet (no I/O/L/0/1 — can't be confused over the phone).

Share it with anyone you want in: they sign up, paste the code on /household/setup, and they're in as a member.

### Switching households

If you're in more than one household, a household-switcher dropdown appears in the top nav. Click your name's household → switch to another. Each household has its own calendar, chores, members, and leaderboard.

### Regenerating the invite code

Owner-only. **Household** page → **Regenerate code** button. The old code stops working immediately. Use this if you accidentally shared the code too widely.

### Transferring ownership

Owner-only. Pick a current member from the dropdown → **Transfer**. You become a member; they become the owner. You can't undo this from your side — they'd have to transfer it back.

### Leaving

Non-owners only. **Household** page → **Leave**. You're removed from the membership. If you had chores assigned, they become unassigned (someone else picks them up). Your past contributions stay on the leaderboard.

Owners can't leave — they'd orphan the household. Either transfer ownership first or delete it.

### Deleting

Owner-only. **Household** page → **Delete**. You'll be asked to type the household name exactly to confirm. Everything goes: chores, events, leaderboard, members. Other members get redirected to /household/setup on their next request.

---

## 6. Your account

### Change your display name

**/me/profile**. Type the new name, **Save**. Updates everywhere immediately — chore assignments, leaderboard, member rosters.

### Change your password

**/me/password**. You'll need your current password (proves it's you) and a new one (12–128 chars, must differ).

After success: your session continues uninterrupted, but **any other browser/device you're signed into with the OLD password gets bounced to /login on its next request**. This is by design — if someone stole your laptop's session yesterday, changing the password kicks them.

### Forgot your password?

On the **/login** page, click **Forgot password?** below the password field.

1. Enter your email, **Send reset link**.
2. You'll see "Check your email." Mishka shows this whether the email is registered or not (avoids leaking which emails have accounts).
3. Open the email. Click the reset link.
4. Set a new password.
5. You're redirected back to /login. Sign in with the new password.

Reset links work **once** and expire in **1 hour**. If you don't click in time, request a new one.

Don't have access to the email account? Ask the household owner / admin to recover it for you. There's no other path.

### "Why was I signed out?"

If you're suddenly looking at /login with a "Your password was changed elsewhere" message: it was. Someone (possibly you) changed your password from another browser/device. Sign in again with the new password. The old session is gone.

---

## 7. Notifications

mishka can send you push notifications when:

- An event in your active household is about to start (default: 15 min before; you choose 0–120 min).
- You have overdue chores in the morning (07:30–08:30 in your household's timezone). One push per day, summarising; not one push per chore.
- *(v0.6.6)* A household member assigns you a new chore — fires at chore-creation time. Self-assigned chores don't push you. Edits to existing chores don't push either.
- *(v0.6.6)* A household member adds a new event to a household calendar — fires once at event-creation time (recurring series push once at series-creation, not per occurrence). The event-creator never gets their own push.

### Enable on a device

1. Open **/me/notifications** in the browser you use most.
2. Click **Enable on this device**.
3. Grant the permission prompt your browser shows.
4. You'll see the device appear in the "Subscribed devices" list below.

Each browser/device subscribes independently — phone + laptop = two subscriptions. Mishka pushes to all of them.

### iPhone / iPad

Apple only delivers Web Push on iOS 16.4+ AND when the app is installed as a Progressive Web App (PWA) on your home screen. Mishka v0.6.3 ships a real PWA manifest, so after install you'll get a true standalone app — not just a bookmark.

> **⚠️ Already added Mishka to your Home Screen before v0.6.3?**
>
> You have the old bookmark-style icon. iOS snapshots PWA settings at install time, so your existing icon will *never* receive push notifications. **Delete the existing Mishka icon from your Home Screen, then re-add it via the steps below.** This is a one-time fix per device.

To install:

1. Open `https://mishka.minified.work` in **Safari** (not Chrome — Safari is the only browser that can install a PWA on iOS).
2. Tap the **Share** icon at the bottom (square with the up-arrow).
3. Scroll and tap **Add to Home Screen**.
4. Tap **Add**.
5. Open mishka from your home-screen icon (it should launch full-screen with no Safari address bar — that means the PWA is working).
6. Navigate to **/me/notifications** and tap **Enable on this device**.

If you're on iOS older than 16.4, push won't arrive — you'll still see everything in the in-product surfaces (calendar, chores, leaderboard).

### Test it

Click **Send test push** on /me/notifications. A notification with "🐻 Mishka push works" should arrive on every device you've enabled within a few seconds. If it doesn't, check the browser's notification permissions for `mishka.minified.work`.

### Tune the timing

Four prefs on /me/notifications:
- **Event reminder** — off, 5/10/15/30 min, 1 h, 2 h before. Applies to every event in every household you belong to.
- **Daily 7:30–8:30am digest if I have overdue chores** — checkbox. Single push at the start of your morning; nothing if all chores are caught up.
- *(v0.6.6)* **Push me when a household member assigns me a new chore** — checkbox, on by default. Untick if you'd rather find new chores yourself.
- *(v0.6.6)* **Push me when a new event is added to a household calendar** — checkbox, on by default. Untick if you only care about reminders, not "FYI" notifications.

### Stop notifications

- Revoke a single device on /me/notifications → **Revoke** next to its row.
- Or turn off notification permission in your browser entirely for `mishka.minified.work`.
- Or set Event reminder to "Off" and uncheck the digest — pushes stop without unsubscribing the device.

### Offline (v0.6.7)

Mishka caches a small set of static assets (manifest, icons, push-subscribe.js) and a session-state-free `/offline` shell. What you'll see offline:

- Pages you've already visited may load from the cache (briefly — Mishka prefers a fresh network response and falls back to cache only on network failure, so most pages still show "loading" until either the network responds or the 3-second timeout fires).
- Navigating to an uncached route while offline falls back to the `/offline` shell — a tiny "you're offline" page that doesn't show any household data.
- Form submissions (marking a chore done, creating an event, saving prefs) require a live network. Mishka does NOT queue writes for later — try again once you're back online.

Mishka updates its offline cache silently on every release; there's no "update available" banner to dismiss.

**If you submit a form and the page shows "CSRF token mismatch" as plain black text on a white background**, your session changed in another tab while this page was sitting open. Press your browser back button and reload the form. (v0.6.8 may add a smoother recovery for this edge case.)

## 8. Troubleshooting

### "Please verify your email" won't go away

You haven't clicked the link in the verification email yet. Or the link expired (24 hours). Click **Resend** on the banner; a fresh link arrives.

If no email arrives at all: ask your operator whether MailHog/Postmark is configured correctly. The dev environment might be sinking the email instead of delivering it.

### Email never arrived (password reset)

Same flow: check spam, then ask the operator to check the mail server. Password-reset uses the same email pipeline as verification.

### Calendar event on the wrong day

If you added an event for, say, June 30 but it's showing on June 29: that was a known bug fixed in v0.5.1. Make sure you're on the latest version. If it's still happening, file a bug — there's a regression test pinning the correct behaviour.

### "This link is invalid or expired"

The reset / verify link has been:
- Used already (single-use)
- Past its expiry (1h reset, 24h verify)
- Or doesn't match any token (typo'd URL)

Request a new one. Reset: /password-reset. Verify: sign in, click **Resend** on the banner.

### CSRF token errors (403 on form submit)

You probably left a tab open for hours. The session expired. Refresh the page, sign in again, try again.

### "Owners cannot leave a household"

You need to either **transfer ownership** to another member first, or **delete** the household entirely. There's no shortcut — Mishka doesn't let households become ownerless.

---

## 9. Privacy and security

A one-pager on what Mishka does and doesn't do with your data, for the curious / paranoid.

### What stays on the server

- All your data: events, chores, completions, points history, member list.
- Your password as an **argon2id hash** — not the password itself. Mishka can never tell you what your password is; it can only verify a candidate.
- Email addresses (for sign-in + recovery).

### What goes out over email

- The verification link (one-time, 24h).
- The password-reset link (one-time, 1h).
- That's it. No marketing, no third-party tracking pixels, no analytics.

### What goes out over your iCal feed

If you subscribed your phone calendar to Mishka's feed: every event in every household you belong to, refreshed continuously. The feed URL is a **bearer token** — anyone who has it can read your full calendar. Treat it like a password. The Feed page lets you revoke and rotate.

### Session security

- Sessions use HttpOnly + Lax SameSite cookies (no JavaScript can read them, cross-site form submits don't carry them).
- Logging in rotates the session ID (defends against fixation).
- Changing your password rotates the session ID AND invalidates every other session you had. The bounced device sees "Your password was changed elsewhere."

### Token URL privacy

The /password-reset/{token} and /verify-email/{token} URLs are bearer credentials for the brief window they're valid. Mishka sets `Referrer-Policy: no-referrer` on those pages, so if you click an external link from one of them, the destination site **doesn't** get to see your token in the `Referer` header. The token also doesn't appear in server access logs (redacted by the reverse proxy).

### The boot guard

In a sane deploy, Mishka **refuses to start** unless `APP_URL` is set to its real public hostname. This means a malicious request with a forged `Host:` header can't trick Mishka into sending you an email link pointing at an attacker-controlled server. The URL builder reads only the boot-time value.

### What's NOT secured

- Mishka v0.5 doesn't encrypt event content at rest. The household's database has full read access to everyone's events. Trust your DB operator.
- There's no 2FA / TOTP. Password is the only sign-in factor.
- No per-device session list / revoke-this-session UI yet. Changing your password is the all-or-nothing session-revoke lever.

For more: [DOCS.md](../DOCS.md) and [docs/ACCOUNT.md](ACCOUNT.md).
