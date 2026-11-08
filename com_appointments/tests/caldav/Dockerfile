# Client container of the caldav-clients job: Python caldav and vdirsyncer,
# talking to the Joomla test container over the compose network. Built and run
# by run-clients.sh; nothing of it reaches the installation package.
FROM python:3.13-slim

COPY requirements.txt /opt/caldav-clients/requirements.txt
RUN pip install --no-cache-dir -r /opt/caldav-clients/requirements.txt

COPY clients.py /opt/caldav-clients/clients.py
COPY authenticated.py /opt/caldav-clients/authenticated.py
COPY test_authenticated.py /opt/caldav-clients/test_authenticated.py
RUN python -m unittest discover -s /opt/caldav-clients -p test_authenticated.py

WORKDIR /work
ENTRYPOINT ["python", "-I", "/opt/caldav-clients/clients.py"]