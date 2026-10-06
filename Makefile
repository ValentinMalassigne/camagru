MAKEFLAGS += --silent

#RULES
all: build
	$(MAKE) up
	@echo "[CAMAGRU] ==> Website's up! go to http://localhost"

build: setup
	docker compose build

re: fclean
	$(MAKE) all

up: setup
	docker compose up -d

stop:
	docker compose stop

clean:
	docker compose down

fclean:
	docker compose down -v --rmi local

logs:
	docker compose logs

setup:
	if [ ! -f .env ]; then \
		echo "[CAMAGRU] ==> No .env file found. Please set one before attempting to build the website." ;\
		exit 1; \
	fi

.PHONY: all build up stop clean fclean logs setup
