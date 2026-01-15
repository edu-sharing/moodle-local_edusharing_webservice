FROM moodlehq/moodle-php-apache:8.3

ARG MOODLE_BRANCH=MOODLE_405_STABLE

RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*

## Get Moodle code
RUN rm -rf /var/www/html/* && \
    git clone -b ${MOODLE_BRANCH} --depth 1 git://git.moodle.org/moodle.git /var/www/html

## Clone/copy ES-Plugins to temp folder
RUN mkdir -p /edusharing
RUN git clone https://github.com/edu-sharing/moodle-mod_edusharing.git /edusharing/edusharing && \
    cd /edusharing/edusharing && \
    git submodule update --init --recursive

COPY . /edusharing/edusharing_webservice

## Further Plugins

## Question type Stack (qtype stack) is on VHB Render Moodle,
## but not fully configured (see https://docs.stack-assessment.org/en/Installation/)
## If we want to support it we will have to do additional config

## Question type word select
RUN git clone -b v2.54 --depth 1 https://github.com/marcusgreen/moodle-qtype_wordselect.git /var/www/html/question/type/wordselect

## Legacy H5P
RUN git clone -b stable https://github.com/h5p/moodle-mod_hvp.git /var/www/html/mod/hvp && \
    cd /var/www/html/mod/hvp && \
    git submodule update --init --recursive

## Code Runner (VHB) -  requires further config (JOBE server)
RUN git clone https://github.com/trampgeek/moodle-qtype_coderunner.git question/type/coderunner && \
    git clone https://github.com/trampgeek/moodle-qbehaviour_adaptive_adapted_for_coderunner.git question/behaviour/adaptive_adapted_for_coderunner

RUN mkdir -p /var/www/moodledata && \

    chown -R root:root /var/www/html && \

    chown -R www-data:www-data /var/www/moodledata && \

    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \;

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
