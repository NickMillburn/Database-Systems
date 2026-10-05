#creating fake data with faker
from faker import Faker
fakeData = Faker()

#we will fake a movie name with words
def capitalize(str):
    return str.capitalize()

words = fakeData.words()
capitalized_words = list(map(capitalize, words))
movie_name = ' '.join(capitalized_words)
print(movie_name)

#We want a date for our movie
from datetime import datetime
date = datetime.strftime(fakeData.date_time_this_decade(), "%B %d, %Y")
print(date)

#we need a genre for our movie
from faker.providers import BaseProvider
import random

class GenreGenerator(BaseProvider):
    def movie_genre(self):
        return random.choice(['Documentary', 'Thriller', 'Mystery', 'Horror', 'Action', 'Comedy', 'Drama', 'Romance'])
    
fakeData.add_provider(GenreGenerator)
movie_genre = fakeData.movie_genre()
print(movie_genre)

#we can specify the language using the same strategy
class LanguageGenerator(BaseProvider):
    def movie_language(self):
        return random.choice(['English', 'Spanish', 'French', 'Korean', 'Japanese', 'Hindi', 'Chinese', 'Italian'])
    
fakeData.add_provider(LanguageGenerator)
movie_language = fakeData.movie_language()
print(movie_language)

#we need a duration for the movie...between 50 and 150 minutes
movie_length = random.randrange(50, 150)
print(movie_length)

#finally, we need a rotton tomatoes score
movie_rating = round(random.uniform(1.0, 10.0), 1)
print(movie_rating)