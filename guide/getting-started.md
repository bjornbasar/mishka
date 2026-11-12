# Getting Started

## Sign up

Go to **Register**, linked from the front page. You'll need:

- **Email** — used to sign in and recover a forgotten password.
- **Display name** *(optional)* — what other household members see. If you
  skip it, Mishka uses the part of your email before the `@`.
- **Password** — 12–128 characters.

Registration is open to anyone — there's no invite gate on signing up
itself. What's private is the household you join afterwards.

## Verify your email

Right after registering, Mishka sends a verification email with a
single-use link that expires in 24 hours. A banner stays on every page
until you click it (with a **Resend** option if it expires). You can use
Mishka before verifying — nothing's blocked — the banner is just a
reminder.

## Create or join a household

Two choices:

- **Create a household** (you become the owner) — pick a name, and Mishka
  shows you an 8-character invite code to share with whoever else should
  join.
- **Join a household** (someone else is the owner) — enter the code they
  gave you.

## Signing in again

Forgot your password? **Login** has a reset link — enter your email, a
reset link arrives (single-use, expires in 1 hour), set a new password.
Mishka shows the same "check your email" message whether or not the
address is actually registered, so it can't be used to discover who has
an account.

## Routes

| Route |
|---|
| `/` |
| `/register` |
| `/login` |
| `/logout` |
| `/household/setup` |
| `/verify-email/{token}` |
| `/me/verify-email/resend` |
| `/password-reset` |
| `/password-reset/{token}` |
