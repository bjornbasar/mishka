# Account

## Profile

Change your display name — it updates everywhere immediately: chore
assignments, the leaderboard, member rosters.

## Password

Changing it requires your current password. After a successful change,
your own session continues uninterrupted, but any other browser or device
still signed in with the old password gets signed out on its next
request — by design, so a stolen or forgotten session elsewhere gets
kicked the moment you notice and change your password.

## Email

Changing your email requires clicking a verification link sent to the
*new* address before it takes effect — this protects against someone
with access to your signed-in session quietly redirecting password
resets to an address you don't control.

## Sessions

See every device/browser currently signed into your account, and revoke
one specifically or all others at once — useful if you spot a session you
don't recognise, or just want to sign out everywhere except where you are
right now.

## Deleting your account

Removes your account entirely. If you're a household owner, transfer
ownership or delete the household first — the same rule as leaving a
household.

## Site admin

A separate, site-wide role from household ownership, held by whoever
operates the Mishka instance itself — not something most users will ever
touch.

## Routes

| Route |
|---|
| `/me/profile` |
| `/me/password` |
| `/me/email` |
| `/me/email-change/{token}` |
| `/me/delete` |
| `/me/sessions` |
| `/me/sessions/{id}/revoke` |
| `/me/sessions/revoke-others` |
| `/me/admin/promote` |
