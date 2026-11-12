# Calendar

A month grid and an agenda view, both scoped to your current household —
events you add are visible to everyone in it.

## Adding an event

Fill in title, start, end, and optionally location and description. Times
are shown in your household's timezone, so every member sees the same
time regardless of where they're connecting from.

## Recurring events

Set how it repeats — daily, weekly (any combination of days), monthly (a
day of month, or "the 2nd Tuesday" style), yearly, or every N of any of
those. The series continues until you delete it.

## Editing a single occurrence

Open any event on the grid and you can edit **just that occurrence**
(e.g. "next Wednesday moves to Thursday because it's a holiday") without
touching the rest of the series — or cancel just that one, or restore a
previously-cancelled one. This is the same idea as "edit this event only"
in Google Calendar or Outlook.

## Subscribing from your phone

Mishka can generate a personal, secret URL you paste into Apple Calendar,
Google Calendar, Outlook, or any app that reads iCal feeds. The URL
updates in near-real-time as events are added. The raw link is shown only
once, right when you generate it — revisiting the page later shows when
it was created and last used, not the link itself. If it ever leaks,
revoke it and generate a fresh one.

## Routes

| Route |
|---|
| `/calendar` |
| `/calendar/agenda` |
| `/calendar/events/new` |
| `/calendar/events` |
| `/calendar/events/{id}` |
| `/calendar/events/{id}/delete` |
| `/calendar/events/{id}/occurrences/{key}/edit` |
| `/calendar/events/{id}/occurrences/{key}` |
| `/calendar/events/{id}/occurrences/{key}/cancel` |
| `/calendar/events/{id}/occurrences/{key}/restore` |
| `/me/calendar/feed` |
| `/me/calendar/feed/generate` |
| `/me/calendar/feed/tokens/{id}/revoke` |
| `/ical/{token}.ics` |
