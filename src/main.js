const content = document.getElementById('content');
const wrapper = document.getElementById('wrapper');

window.addEventListener('load', () => {
    const url = new URL(window.location.href);

    const queryParams = [];

    url.searchParams.forEach((value, key) => {
        queryParams[key] = value;
    });

    if ('code' in queryParams) {
        saveCode(queryParams.code);
    } else {
        checkAuthenticationStatus();
    }
});

const checkAuthenticationStatus = () => {
    fetch('http://localhost:8080/GPhotosGateway.php?action=checkAuthenticationStatus').then((response) => {
        return response.json();
    }).then((data) => {
        switch (data.status) {
            case 'authorized':
                wrapper.innerHTML = `
                    <button id="download-media" class="action-button">Listar e Baixar Mídias</button>
                `;

                document.getElementById('download-media').addEventListener('click', () => {
                    fetchMedia();
                });

                break;

            case 'valid_credentials':
                wrapper.innerHTML = `
                    <button id="connect-account" class="action-button">Conectar Conta</button>
                `;

                document.getElementById('connect-account').addEventListener('click', () => {
                    connectAccount();
                });

                break;

            case 'invalid_credentials':
                content.innerHTML = `
                    <div id="alert-messsage">
                        Arquivo <strong>credentials.json</strong> não encontrado. Caso ainda não o tenha criado: 
                        <a href="https://developers.google.com/photos/library/guides/get-started">
                            https://developers.google.com/photos/<wbr>library/guides/get-started
                        </a>
                    </div>
                `;

                break;
        }
    });
}

const connectAccount = () => {
    fetch('http://localhost:8080/GPhotosGateway.php?action=connectAccount').then((response) => {
        return response.json();
    }).then((data) => {
        if (data.authorization) {
            window.location.href = data.authorization;
        } else {
            content.innerHTML = `
                <div id="alert-messsage">
                    Não foi possível criar a conexão, verifique se os dados do arquivo <strong>credentials.json</strong> estão corretos.
                </div>
            `;
        }
    });
}

const saveCode = (code) => {
    fetch(`http://localhost:8080/GPhotosGateway.php?action=saveCode&code=${code}`).then((response) => {
        return response.json();
    }).then((data) => {
        window.location.href = data.redirect;
    });
}

const fetchMedia = () => {
    const eventSource = new EventSource('http://localhost:8080/GPhotosGateway.php?action=fetchMedia');

    const button = document.getElementById('download-media');

    let emptyTable = true;

    button.innerHTML = '<div class="loader"></div>';

    eventSource.onmessage = function (event) {
        if (emptyTable) {
            content.innerHTML = `
                <table id="table-content">
                </table>
            `;

            emptyTable = false;
        }

        let table = document.getElementById('table-content');

        if (event.data === 'EOF') {
            eventSource.close();
        } else {
            table.insertRow().insertCell(0).innerHTML = event.data;

            content.scrollTop = content.scrollHeight;
        }
    };

    eventSource.onerror = function (event) {
        console.error('Erro ao receber mensagens:', event);
    };
}