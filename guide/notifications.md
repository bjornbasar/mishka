# Notifications

Mishka can push a notification to your device for:

- An event about to start (you choose how far ahead, 0–120 minutes).
- A daily morning digest if you have overdue chores — one push,
  summarising, not one per chore.
- A household member assigning you a new chore (on by default — turn it
  off if you'd rather just check yourself).
- A new event added to a household calendar you're in (on by default).

## Enabling it

Open the Notifications page in the browser you use most, click to enable,
and grant the permission your browser prompts for. Each browser/device
subscribes independently, so a phone and a laptop are two separate
subscriptions Mishka pushes to both of.

On iPhone/iPad, Apple only delivers push to a Progressive Web App
installed to your Home Screen (Safari → Share → Add to Home Screen) on
iOS 16.4+ — a plain browser tab or bookmark can't receive it.

A **Send test push** button confirms it's working before you rely on it.

## Managing it

Tune the event-reminder timing and the two chore/event digest toggles, or
revoke a specific device's subscription, from the same page.

## Using Mishka offline

A small set of static assets and a lightweight offline page are cached so
something always loads even without a connection — but it never shows
household data offline, and nothing you do (marking a chore done, saving
an event) is queued for later; those need a live connection to actually
save.

## Routes

| Route |
|---|
| `/me/notifications` |
| `/me/push/subscribe` |
| `/me/push/subscriptions/{id}/delete` |
| `/me/push/test` |
| `/offline` |
