# Household

## Inviting members

The invite code is on the Household page, visible only to the owner —
share it with anyone who should join; they enter it on the setup page
from their own account.

## Switching households

If you belong to more than one, a switcher appears in the nav. Each
household has its own calendar, chores, health data, and leaderboard —
switching changes which one you're looking at everywhere in the app.

## Owner-only actions

- **Rename** the household.
- **Remove a member.**
- **Regenerate the invite code** — the old code stops working immediately;
  useful if it was shared too widely.
- **Transfer ownership** to another current member — you become a regular
  member, they become the owner. This can't be undone from your side; they'd
  have to transfer it back.
- **Delete** the household entirely — chores, events, health data, and
  membership all go with it.

## Leaving

Any member who isn't the owner can leave at any time — their assigned
chores become unassigned for someone else to pick up, but their past
contributions stay on the leaderboard. Owners can't leave directly; they
have to transfer ownership or delete the household first, since a
household can never be left without an owner.

## Routes

| Route |
|---|
| `/household` |
| `/household/rename` |
| `/household/members/{userId}/remove` |
| `/household/switch` |
| `/household/regenerate-code` |
| `/household/leave` |
| `/household/transfer` |
| `/household/delete` |
