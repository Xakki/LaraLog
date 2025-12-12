SHELL = /bin/bash
### https://makefiletutorial.com/

include .env
export

docker := docker run -it -v $(PWD):/app ${DOCKER_USER}/${TAG}
composer := $(docker) composer

docker-login:
	docker login ${HOST} -u ${DOCKER_USER} -p ${DOCKER_PASS}

docker-push:
	docker push ${DOCKER_USER}/${TAG}

docker-build:
	docker pull ${PHP_IMAGE}
	docker build -t ${DOCKER_USER}/${TAG} --build-arg PHP_IMAGE=${PHP_IMAGE} .

bash:
	$(docker) bash

composer-i:
	$(composer) i

composer-u:
	$(composer) u $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

phpstan:
	$(composer) phpstan

phpunit:
	$(composer) phpunit

test:
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit
