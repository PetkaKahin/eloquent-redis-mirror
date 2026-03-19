.PHONY: tests stan

tests:
	docker-compose run --rm app vendor/bin/pest

stan:
	docker-compose run --rm app vendor/bin/phpstan analyse --memory-limit=512M