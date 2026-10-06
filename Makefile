MAKEFLAGS += --silent

#RULES
all: build
	$(MAKE) up
	@echo "[CAMAGRU] ==> Website's up! go to http://localhost"

build: setup
	docker compose build

up: setup
	docker compose up -d

stop:
	docker compose stop

clean:
	docker compose down

fclean:
	docker compose down -v --rmi local

logs:
	@echo "== app.log (application errors) =="
	@docker compose exec -T php tail -n 100 /var/log/camagru/app.log 2>/dev/null \
		|| echo "  (no file: nothing logged yet)"
	@echo "== php_errors.log (PHP engine) =="
	@docker compose exec -T php tail -n 100 /var/log/camagru/php_errors.log 2>/dev/null \
		|| echo "  (no file: nothing logged yet)"
	@echo "== msmtp.log (email sending) =="
	@docker compose exec -T php tail -n 100 /var/log/camagru/msmtp.log 2>/dev/null \
		|| echo "  (no file: no email sent yet)"
	@echo "== nginx_error.log (nginx errors) =="
	@docker compose exec -T php tail -n 100 /var/log/camagru/nginx_error.log 2>/dev/null \
		|| echo "  (no file: no nginx error yet)"
	@echo "== container consoles (expected: empty) =="
	docker compose logs --tail 100

setup:
	if [ ! -f .env ]; then \
		echo "[CAMAGRU] ==> No .env file found. Please set one before attempting to build the website." ;\
		exit 1; \
	fi

.PHONY: all build up stop clean fclean logs setup
