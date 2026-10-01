# The bot bridge: one HTTP endpoint per property, in front of a chat model.
#
# Python with no dependencies, so there is nothing to install and nothing to keep
# patched beyond the base image itself.
FROM python:3.12-slim

WORKDIR /app
COPY bridge.py .

# Render supplies PORT; this is the default for running it anywhere else.
ENV PORT=8080
EXPOSE 8080

# Unbuffered, so the per-question log lines appear in Render's log tail as they
# happen rather than whenever the buffer fills.
CMD ["python3", "-u", "bridge.py"]
