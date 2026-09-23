# ContextShuttle build config
#
# CI (develop.yaml / docker.yaml) invokes this with --file so the compose
# file that lives in the same directory is not merged in as extra targets.
#
# DOCKERHUB_TARGET is the org/repo (Gitea Settings → Variables)
# CI sets TAG=latest + VERSION=<v-stripped> for tag pushes,
#         TAG=develop  for pushes to main.

variable "DOCKERHUB_TARGET" {
  default = "digitaladapt/context-shuttle"
  description = "Docker Hub repo/org (Gitea repo variable DOCKERHUB_TARGET)."
}

variable "TAG" {
  default = "latest"
  description = "Base tag for this build: latest (release), develop (main push), or a version."
}

variable "VERSION" {
  default = ""
  description = "Optional full version (v stripped) to also tag with; empty for develop builds."
}

group "default" {
  targets = ["app"]
}

target "app" {
  dockerfile = "Dockerfile"
  target     = "app"
  context    = "."
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}"] : [],
  )
}
