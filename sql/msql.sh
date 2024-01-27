user=`head -1 admin.sql | gawk '{print  $6}' - | cut -d\' -f2 -`
password=`head -1 admin.sql | gawk '{print $9}' - | cut -d\' -f2 -`
db=`head -2 admin.sql | gawk '/DATABASE/  { print $6 }' - | cut -d\; -f1 -`

#echo user $user
#echo pass $password
#echo db $db

mysql -u $user -p$password $db  $1

