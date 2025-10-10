const express = require('express');
const session = require('express-session');
const axios = require('axios');
const path = require('path');

const app = express();

app.use(session({
    secret: 'your-secret-key',
    resave: false,
    saveUninitialized: true
}));
app.use(express.urlencoded({ extended: true }));
app.use(express.static('public'));

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

function getClientIP(req) {
    const headers = ['x-forwarded-for', 'x-real-ip', 'x-client-ip'];
    
    for (const header of headers) {
        const ip = req.headers[header];
        if (ip) {
            const ips = ip.split(',');
            for (const ip of ips) {
                const trimmedIP = ip.trim();
                if (isValidIP(trimmedIP)) {
                    return trimmedIP;
                }
            }
        }
    }
    return req.ip || '127.0.0.1';
}

function isValidIP(ip) {
    return /^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(ip);
}

async function callIPAPI(input = '') {
    try {
        const response = await axios.get(`https://apikey.net/api/index?ip=${encodeURIComponent(input)}`, {
            timeout: 10000,
            headers: { 'User-Agent': 'IP Query Tool/1.0' }
        });
        
        if (response.status === 200 && response.data.code === 200) {
            return response.data;
        }
    } catch (error) {
        return { error: `API请求失败: ${error.message}`, input };
    }
    
    return { error: 'API请求失败', input };
}

app.get('/', async (req, res) => {
    const clientIP = getClientIP(req);
    const clientData = await callIPAPI(clientIP);
    
    const queryInput = req.query.ip || '';
    let queryResult = null;
    let isDomainQuery = false;
    
    if (queryInput) {
        queryResult = await callIPAPI(queryInput);
        
        if (queryResult && queryResult.resolvedIPs) {
            isDomainQuery = true;
        }
        
        // 保存查询历史
        if (!req.session.queryHistory) {
            req.session.queryHistory = [];
        }
        
        const history = req.session.queryHistory;
        if (!history.includes(queryInput)) {
            history.unshift(queryInput);
            if (history.length > 10) {
                history.pop();
            }
            req.session.queryHistory = history;
        }
    }
    
    const serverTime = new Date().toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    }).replace(/\//g, '年').replace(/\//g, '月') + '日';
    
    res.render('index', {
        clientData,
        queryResult,
        queryInput,
        isDomainQuery,
        queryHistory: req.session.queryHistory || [],
        serverTime
    });
});

app.post('/', (req, res) => {
    const queryInput = req.body.ip;
    if (queryInput) {
        res.redirect(`/?ip=${encodeURIComponent(queryInput)}`);
    } else {
        res.redirect('/');
    }
});

app.listen(3000, () => {
    console.log('Server running on port 3000');
});