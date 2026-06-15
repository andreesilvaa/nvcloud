pipeline {
    environment {
        QODANA_TOKEN = credentials('qodana-token')
    }
    agent {
        docker {
            args '''
                -v "${WORKSPACE}":/data/project
                --entrypoint=""
                '''
            image 'jetbrains/qodana-php'
        }
    }
    stages {
        stage('Qodana') {
            when {
                branch 'main'
            }
            steps {
                sh '''qodana'''
            }
        }
    }
}