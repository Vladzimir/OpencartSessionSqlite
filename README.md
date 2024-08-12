#Fast and secure session driver for opencart
##Install for opencart 2.3.*

Copy file to root
Update modifications
In file system/framework.php replace
```php
$session = new Session();
```
to
```php
$session = new Session('sqlite');
```
##Bonus.
Fix cookie expiration time update problem