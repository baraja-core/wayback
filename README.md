Wayback machine API
===================

Simple wayback interface for archive.org.

📦 Installation & Basic Usage
-----------------------------

To install the package call Composer and execute the following command:

```shell
$ composer require baraja-core/wayback
```

No configuration is needed, the package will take care of the dependencies itself. The use of DIC is not required. The cache is automatically stored on the filesystem.

How to use
----------

Simply create instance of Wayback and call methods:

```php
$wayback = new Wayback;

// Return list of available archives by host
$wayback->getArchivedUrlsByHost('baraja.cz');

// Return list of available archives by URL (http/https and www will be ignored)
$wayback->getArchivedUrls('https://php.baraja.cz/navody');

// Return list of crawled subdomains (for large sites can not be complete)
$wayback->getSubdomains('baraja.cz');

// Save now given URL to Wayback
$wayback->saveUrl('https://baraja.cz');
```

The return of all results from the Wayback Machine is subject to caching. The results are automatically cached on your file system.

📄 License
-----------

`baraja-core/wayback` is licensed under the MIT license. See the [LICENSE](https://github.com/baraja-core/wayback/blob/master/LICENSE) file for more details.
