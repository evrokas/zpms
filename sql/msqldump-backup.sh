dir=`dirname $0`

user=`head -1 $dir/admin.sql | gawk '{print  $6}' - | cut -d\' -f2 -`
password=`head -1 $dir/admin.sql | gawk '{print $9}' - | cut -d\' -f2 -`
db=`head -2 $dir/admin.sql | gawk '/DATABASE/  { print $6 }' - | cut -d\; -f1 -`

#echo user $user
#echo pass $password
#echo db $db
options='-y --skip-opt --skip-extended-insert'

ignore_tables=('analytics')


for temp in ${ignore_tables[@]}; do full_ignore_tables="$full_ignore_tables --ignore-table=$db.$temp"; done

#echo "IGNORE: " $full_ignore_tables

#exit
options="$options $full_ignore_tables"


#mysqldump -u $user -p$password $db $options $@
MYSQL_PWD="$password" mysqldump -u $user  $db $options $@

echo "error $?"

#mysqldump --defaults-extra-file=<(echo $'[client]\npassword='"$password") -u $user $db $@
