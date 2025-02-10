SHELL = /bin/bash
### https://makefiletutorial.com/

include .env
export

docker := docker run -it -v $(PWD):/app ${DOCKER_USER}/${TAG}
composer := $(docker) composer

docker-login:
	docker login ${HOST} -u ${DOCKER_USER} -p ${DOCKER_PASS}

docker-build:
	docker pull php:8.3-cli-alpine
	docker build -t ${DOCKER_USER}/${TAG} .

docker-push:
	docker push ${DOCKER_USER}/${TAG}

bash:
	$(docker) bash

composer-install:
	$(composer) install

composer-up:
	$(composer) update $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

test:
	$(composer) phpunit

phpstan:
	$(composer) phpstan
