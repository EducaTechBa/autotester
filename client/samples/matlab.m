disp("")

function mandati = Zadatak1(glasovi, broj_mjesta)

[a, ~] = size(glasovi);

dijelitelj = 2;
glasovi_fixed = glasovi;

broj_zauzetih_mjesta=0;

mandati = zeros(a,1);

while dijelitelj<10000
    glasovi = [glasovi, glasovi_fixed/dijelitelj];
    dijelitelj = dijelitelj + 1; 
end

while broj_zauzetih_mjesta < broj_mjesta
    [i,j] = find(glasovi == max(glasovi(:)));
    glasovi(i,j) = 0;
    mandati(i,1) = mandati(i,1)+1;
    broj_zauzetih_mjesta = broj_zauzetih_mjesta+1;
end

end

disp(mat2str(Zadatak1([53420; 29632; 28104; 22020; 20215; 13300; 10477; 7947],30)))
