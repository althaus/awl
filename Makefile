#!/usr/bin/make -f
# 

package=libawl-php
version=$(shell cat VERSION)

all: built-docs 

built-docs: docs/api/phpdoc.ini inc/*.php
	phpdoc -c docs/api/phpdoc.ini
	touch built-docs

#
# Build a release .tar.gz file in the directory above us
#
release: built-docs
	-ln -s . $(package)-$(version)
	tar czf ../$(package)-$(version).tar.gz \
	    --no-recursion --dereference $(package)-$(version) \
	    $(shell git-ls-files |grep -v '.git'|sed -e s:^:$(package)-$(version)/:) \
	    $(shell find $(package)-$(version)/docs/api/ ! -name "phpdoc.ini" )
	rm $(package)-$(version)
	
clean:
	rm -f built-docs
	-find docs/api/* ! -name "phpdoc.ini" ! -name ".gitignore" -delete
	-find . -name "*~" -delete
	

.PHONY:  all clean release