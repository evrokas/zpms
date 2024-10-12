dir=`dirname $0`

user=`head -1 $dir/admin.sql | gawk '{print  $6}' - | cut -d\' -f2 -`
password=`head -1 $dir/admin.sql | gawk '{print $9}' - | cut -d\' -f2 -`
db=`head -2 $dir/admin.sql | gawk '/DATABASE/  { print $6 }' - | cut -d\; -f1 -`

#echo user $user
#echo pass $password
#echo db $db
options='--skip-opt --skip-extended-insert'

ignore_tables='analytics analyt'

full_ignore_table=`for temp in $ignore_tables; do echo $db.$temp; done`;
echo "IGNORE: " $full_ignore_tables



mysqldump -u $user -p$password $db $options $@
#mysqldump --defaults-extra-file=<(echo $'[client]\npassword='"$password") -u $user $db $@
