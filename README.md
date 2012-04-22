Redis caching with tagging support
===================================

This is a Kohana 3 module for caching to a redis server, with tagging support for objects. This extends Kohana's caching support for platforms with tagging from just memcached-tags and SQLite, to include redis as well.

How objects are cached
======================
Objects are cached in a similar way to `memcached-tags`