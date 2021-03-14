## php-cache


```php
<?php
	new CACHE([
		"storage"       => __DIR__."/cache/",
		"prefix.key"    => "domain.com/",
		"default.time"  => 120,
		"db"            => $app["db"],
	]);
```
