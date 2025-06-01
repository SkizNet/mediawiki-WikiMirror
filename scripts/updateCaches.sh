#!/bin/bash
set -e

################################################################
### Adjust these parameters accordingly for your environment ###
################################################################

# Database name for the wiki running Extension:WikiMirror
DATABASE=my_wiki

# Place to store downloaded dump files
DUMPS=/tmp/dumps

# Path to the wiki's maintenance directory
MAINT_ROOT=/var/www/mywiki.com/w/maintenance

# Database connection credentials should be in a .my.cnf or .mylogin.cnf
# the login path denotes the section name in that file containing the credentials
LOGIN_PATH=client

# Project (dump) name we are downloading
PROJECT=enwiki

########################################################
### End of configuration, do not edit anything below ###
########################################################

rm -f $DUMPS/*.sql
rm -f $DUMPS/*.gz
curl "https://dumps.wikimedia.org/$PROJECT/latest/$PROJECT-latest-page.sql.gz" -o $DUMPS/enwiki-latest-page.sql.gz
curl "https://dumps.wikimedia.org/$PROJECT/latest/$PROJECT-latest-redirect.sql.gz" -o $DUMPS/enwiki-latest-redirect.sql.gz
php $MAINT_ROOT/run.php WikiMirror:UpdateRemotePage --page $DUMPS/enwiki-latest-page.sql.gz --out $DUMPS/remote-page.sql
php $MAINT_ROOT/run.php WikiMirror:UpdateRemotePage --redirect $DUMPS/enwiki-latest-redirect.sql.gz --out $DUMPS/remote-redirect.sql
mysql --login-path=$LOGIN_PATH -D $DATABASE < $DUMPS/remote-page.sql
mysql --login-path=$LOGIN_PATH -D $DATABASE < $DUMPS/remote-redirect.sql
php $MAINT_ROOT/run.php WikiMirror:UpdateRemotePage --finish
