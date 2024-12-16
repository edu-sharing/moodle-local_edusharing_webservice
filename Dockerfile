FROM dockerio.mirror.docker.edu-sharing.com/alpine:latest AS builder

RUN apk add --no-cache -U git
RUN set -eux \
    && git clone https://github.com/edu-sharing/moodle-mod_edusharing.git mod_edusharing \
    && cd mod_edusharing \
    && git submodule init \
    && git submodule update


FROM dockerio.mirror.docker.edu-sharing.com/bitnami/moodle:4.5.1

ARG git_branch=dev
ARG git_closest_tag_fixed=dev
ARG git_commit_id=dev
ARG git_dirty=dev
ARG project_artifactId=edu_sharing-community-common-docker-mongodb
ARG project_groupId=org.edu_sharing
ARG project_version=dev

## Activate docker profile
ENV EDUSHARING_RENDER_DOCKER_DEPLOYMENT=1

COPY . /edusharing/edusharing_webservice
COPY --from=builder mod_edusharing /edusharing/mod_edusharing

COPY ./edusharing-post-init.sh /docker-entrypoint-init.d/edusharing-post-init.sh

RUN chmod +x /docker-entrypoint-init.d/edusharing-post-init.sh

# TODO default value substituion doesn't work because of the '.' delimiter
LABEL git.branch=${git_branch} \
git.closest.tag.name=${git_closest_tag_fixed} \
git.commit.id=${git_commit_id} \
git.dirty=${git_dirty} \
mvn.project.artifactId=${project_artifactId} \
mvn.project.groupId=${project_groupId} \
mvn.project.version=${project_version}
