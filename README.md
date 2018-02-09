# Hipchat to Slack

Script to copy messages from Hipchat rooms to Slack channels.

## Installation
- Clone this repo.
- Copy `config/config.php.dist` to `config/config.php` and update the values accordingly.
- Create a `data` directory that is writable.
- Run `php index.php`.

## Cron job
To run as a cron job every 5 minutes:

- Run `sudo crontab -e` (add to root cron so as not to tie to any user)
- Add the following entry (time in UTC):
  `*/5 * * * * /usr/bin/php /var/www/hipchat-slack/index.php >/dev/null 2>&1`
- Ensure there is a blank line at the end of the crontab
