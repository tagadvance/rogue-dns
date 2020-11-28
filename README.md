# Rogue DNS

Update DNS zones in Cloudflare with dynamically allocated IP address, e.g. when hosting a website at home.

## Installation
```bash
mkdir ~/git
cd ~/git
git clone git@github.com:tagadvance/rogue-dns.git
cd rogue-dns
composer install
cp config-sample.ini config.ini
# add token to config.ini
```

## Configuration
[Create a new Cloudflare API Token](https://dash.cloudflare.com/profile/api-tokens) with the following permissions:
* Zone.Zone Settings
* Zone.Zone
* Zone.DNS

## Usage
```
./cloudflare.php --help
./cloudflare.php --add-zone foo.com
# WARNING: These will update *ALL* of your zones!
./cloudflare.php --update-ip
./cloudflare.php --update-ip 127.0.0.1
```

## Scheduling
`crontab -e`
```
# every minute, e.g.
* * * * * $HOME/git/rogue-dns/cloudflare.php --update-ip

# every 5 minutes, e.g.
*/5 * * * * $HOME/git/rogue-dns/cloudflare.php --update-ip
```
